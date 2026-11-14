<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmoptout.class.php
 * \ingroup crm
 * \brief   From v1.2.0. The public "not interested, don't contact me again soon" link of a deal: the link of a deal, and what
 *          happens when a prospect follows it. The public page only calls record(); every rule is here.
 *
 * Design: the link carries one token, an HMAC of the entity and the deal id under a secret that is not in the database
 * (crmOptOutToken). The table keeps only the SHA-256 of the token, so a copy of the database does not give working links, and
 * the token is found by an exact lookup on that hash. The link is the same in every e-mail of a deal.
 */

require_once __DIR__.'/../lib/crm.lib.php';

/**
 * Link of a deal, and the effect of using it.
 */
class CrmOptOut
{
	/** @var DoliDB */
	private $db;

	/** @var string Language key of the last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * True when the table exists and schema step 5 has run (files are live before the module is enabled again).
	 */
	public static function supported(DoliDB $db): bool
	{
		static $table = null;
		if ($table === null) {
			$resql = $db->DDLDescTable($db->prefix().'crm_optout', 'token_hash');
			$table = ($resql && $db->fetch_object($resql)) ? true : false;
		}
		return $table && crmSchemaInstalled() >= 5;
	}

	/**
	 * The secret of the tokens: the unique id of the installation from conf.php, which is not in the database; for an
	 * installation that has none, a random one kept in the setting CRM_OPTOUT_SECRET (created at first use).
	 */
	private function secret(): string
	{
		global $conf, $dolibarr_main_instance_unique_id;
		if (is_string($dolibarr_main_instance_unique_id) && strlen($dolibarr_main_instance_unique_id) >= 16) {
			return $dolibarr_main_instance_unique_id;
		}
		$stored = getDolGlobalString('CRM_OPTOUT_SECRET');
		if (strlen($stored) < 32) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$stored = bin2hex(random_bytes(32));
			dolibarr_set_const($this->db, 'CRM_OPTOUT_SECRET', $stored, 'chaine', 0, '', (int) $conf->entity);
			$conf->global->CRM_OPTOUT_SECRET = $stored;
		}
		return $stored;
	}

	/**
	 * Public address of the page of a deal, `<instance url>/custom/crm/optout.php?h=<token>`, or '' when it cannot be made. The
	 * hash of the token is stored the first time. The address is the one Dolibarr uses for itself (conf.php): it must be reachable
	 * by the prospects.
	 */
	public function urlForDeal(int $dealId): string
	{
		global $conf;
		if (!self::supported($this->db) || $dealId <= 0) {
			return '';
		}
		$token = crmOptOutToken($this->secret(), (int) $conf->entity, $dealId);
		if ($token === '') {
			return '';
		}
		$hash = hash('sha256', $token);
		$resql = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'crm_optout WHERE fk_opportunity = '.((int) $dealId));
		if ($resql && $this->db->num_rows($resql) === 0) {
			$exists = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'crm_opportunity WHERE rowid = '.((int) $dealId).' AND entity = '.((int) $conf->entity));
			if (!$exists || $this->db->num_rows($exists) === 0) {
				return '';
			}
			$this->db->query('INSERT INTO '.$this->db->prefix().'crm_optout (entity, fk_opportunity, token_hash, datec) VALUES ('.((int) $conf->entity).', '.((int) $dealId).", '".$this->db->escape($hash)."', '".$this->db->idate(dol_now())."')");
		}
		return dol_buildpath('/crm/optout.php', 2).'?h='.$token;
	}

	/**
	 * Does this token belong to a deal? Returns the row (deal id, entity, date_used) or null. Nothing else is learnt from a
	 * wrong token.
	 */
	public function find(string $token): ?object
	{
		if (!crmOptOutHashValid($token) || !self::supported($this->db)) {
			return null;
		}
		$sql = 'SELECT rowid, entity, fk_opportunity, date_used FROM '.$this->db->prefix()."crm_optout WHERE token_hash = '".$this->db->escape(hash('sha256', $token))."'";
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		return $row ?: null;
	}

	/**
	 * Register the prospect's request: an open deal moves to the lost stage of its pipeline with the reason "Not interested", a
	 * done event says why, and its running outreach stop. A deal that is already closed is left as it is.
	 *
	 * @return string 'recorded' (deal changed), 'already' (nothing to change: closed deal or link already used), 'invalid' (unknown
	 *                token) or 'error'
	 */
	public function record(string $token): string
	{
		global $conf, $langs;
		$row = $this->find($token);
		if ($row === null) {
			return 'invalid';
		}
		$conf->entity = (int) $row->entity;
		require_once __DIR__.'/opportunity.class.php';
		require_once __DIR__.'/stage.class.php';
		require_once __DIR__.'/crmactivity.class.php';
		require_once __DIR__.'/sequence.class.php';
		$deal = new Opportunity($this->db);
		if ($deal->fetch((int) $row->fk_opportunity) <= 0) {
			return 'invalid';
		}
		if ($deal->status !== 'open') {
			$this->markUsed((int) $row->rowid);
			return 'already';
		}
		// The deal is changed in the name of its owner, who is told by the history and the event: there is no logged user here.
		$actor = new User($this->db);
		foreach (array((int) $deal->fk_user_resp, (int) $deal->fk_user_creat) as $candidate) {
			if ($candidate > 0 && $actor->fetch($candidate) > 0) {
				break;
			}
		}
		if ((int) $actor->id <= 0) {
			dol_syslog('CRM opt-out: deal '.((int) $deal->id).' has no owner to act as', LOG_ERR);
			return 'error';
		}
		$lostStageId = 0;
		foreach ((new Stage($this->db))->fetchAll((int) $deal->fk_pipeline) as $stage) {
			if ((int) $stage->is_lost === 1 && $lostStageId === 0) {
				$lostStageId = (int) $stage->id;
			}
		}
		$reasonId = 0;
		$resql = $this->db->query('SELECT rowid FROM '.$this->db->prefix().'crm_loss_reason WHERE entity = '.((int) $conf->entity)." AND code = 'NOTINTERESTED' ORDER BY active DESC, rowid");
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$reasonId = (int) $obj->rowid;
		}
		if ($lostStageId <= 0 || $reasonId <= 0) {
			dol_syslog('CRM opt-out: deal '.((int) $deal->id).' has no lost stage or no "Not interested" reason', LOG_ERR);
			return 'error';
		}
		$langs->load('crm@crm');
		$text = $langs->transnoentitiesnoconv('CrmOptOutEvent');
		$this->db->begin();
		if ($deal->moveToStage($actor, $lostStageId, $reasonId, $text) <= 0) {
			$this->db->rollback();
			dol_syslog('CRM opt-out: deal '.((int) $deal->id).' not moved: '.$deal->error, LOG_ERR);
			return 'error';
		}
		$activity = new CrmActivity($this->db);
		$eventId = $activity->create($actor, (int) $deal->id, (int) $deal->fk_soc, (int) $deal->fk_socpeople, (int) $deal->fk_user_resp, 'task', $text, dol_now(), 100);
		if ($eventId <= 0) {
			$this->db->rollback();
			dol_syslog('CRM opt-out: no event for deal '.((int) $deal->id).': '.$activity->error, LOG_ERR);
			return 'error';
		}
		(new Sequence($this->db))->stopOnOptOut((int) $deal->id);
		$this->markUsed((int) $row->rowid);
		$this->db->commit();
		dol_syslog('CRM opt-out recorded for deal '.((int) $deal->id).' (link '.crmOptOutTokenTail($token).')', LOG_INFO);
		return 'recorded';
	}

	private function markUsed(int $rowId): void
	{
		$this->db->query('UPDATE '.$this->db->prefix().'crm_optout SET date_used = COALESCE(date_used, \''.$this->db->idate(dol_now()).'\') WHERE rowid = '.$rowId);
	}
}
