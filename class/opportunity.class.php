<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/opportunity.class.php
 * \ingroup crm
 * \brief   A deal. The account, the contact and the proposals stay Dolibarr records.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';

/**
 * Sales opportunity.
 */
class Opportunity extends CommonObject
{
	/** @var string */
	public $element = 'opportunity';
	/** @var string */
	public $table_element = 'crm_opportunity';
	/** @var int<0,1> */
	public $ismultientitymanaged = 1;
	/** @var string */
	public $picto = 'fa-handshake';

	/** @var string */
	public $ref;
	/** @var string */
	public $label;
	/** @var int */
	public $fk_soc;
	/** @var int */
	public $fk_socpeople;
	/** @var int */
	public $fk_pipeline;
	/** @var int */
	public $fk_stage;
	/** @var float */
	public $amount;
	/** @var int */
	public $date_close_expected;
	/** @var int */
	public $fk_source;
	/** @var int */
	public $fk_user_resp;
	/** @var string */
	public $description;
	/** @var string open|won|lost */
	public $status;
	/** @var int */
	public $date_close;
	/** @var int */
	public $fk_loss_reason;
	/** @var string */
	public $loss_note;
	/** @var int */
	public $datec;
	/** @var int */
	public $fk_user_creat;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->module = 'crm';
	}

	/**
	 * Link shown by the agenda when an event points at this deal.
	 *
	 * @param int    $withpicto 1 to include the picto
	 * @param string $option    Kept for the core signature
	 * @return string
	 */
	public function getNomUrl($withpicto = 0, $option = '')
	{
		global $langs;
		$langs->load('crm@crm');
		$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $this->id;
		$label = $langs->trans('CrmOpportunity').' '.(string) $this->ref;
		$html = '<a href="'.$url.'" title="'.dol_escape_htmltag($label).'">';
		if ($withpicto) {
			$html .= img_object('', $this->picto, 'class="paddingright"');
		}
		$html .= dol_escape_htmltag((string) $this->ref);
		$html .= '</a>';
		return $html;
	}

	/**
	 * Fetch one deal.
	 *
	 * @param int $id Row id
	 * @return int 1 if found, 0 if not, -1 on error
	 */
	public function fetch(int $id): int
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_opportunity WHERE rowid = '.((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if (!$obj) {
			return 0;
		}
		global $conf;
		if ((int) $obj->entity !== (int) $conf->entity) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = (string) $obj->ref;
		$this->label = (string) $obj->label;
		$this->fk_soc = (int) $obj->fk_soc;
		$this->fk_socpeople = (int) $obj->fk_socpeople;
		$this->fk_pipeline = (int) $obj->fk_pipeline;
		$this->fk_stage = (int) $obj->fk_stage;
		$this->amount = (float) $obj->amount;
		$this->date_close_expected = $this->db->jdate($obj->date_close_expected);
		$this->fk_source = (int) $obj->fk_source;
		$this->fk_user_resp = (int) $obj->fk_user_resp;
		$this->description = (string) $obj->description;
		$this->status = (string) $obj->status;
		$this->date_close = $this->db->jdate($obj->date_close);
		$this->fk_loss_reason = (int) $obj->fk_loss_reason;
		$this->loss_note = (string) $obj->loss_note;
		$this->datec = $this->db->jdate($obj->datec);
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		return 1;
	}

	/**
	 * Create a deal in the first open stage of the pipeline and record that entry in the history.
	 *
	 * @param User $user User creating the deal
	 * @return int Row id, or -1
	 */
	public function create(User $user): int
	{
		global $conf;
		$this->label = crmSingleLine(strip_tags((string) $this->label), 255);
		if ($this->fk_pipeline <= 0 || $this->label === '' || $this->fk_user_resp <= 0) {
			$this->error = 'CrmErrorDealIncomplete';
			return -1;
		}
		if (!$this->validateLinks($user, 0, 0)) {
			return -1;
		}
		$stageId = (int) $this->fk_stage;
		if ($stageId <= 0) {
			$stageId = $this->firstOpenStage($this->fk_pipeline);
		}
		if ($stageId <= 0) {
			$this->error = 'CrmErrorPipelineNoOpenStage';
			return -1;
		}
		if ($this->ref === '' || $this->ref === null) {
			$this->ref = crmNextOpportunityRef($this->db);
		}
		$now = dol_now();
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_opportunity (';
		$sql .= 'entity, ref, label, fk_soc, fk_socpeople, fk_pipeline, fk_stage, amount, date_close_expected, fk_source, fk_user_resp, description, status, datec, fk_user_creat';
		$sql .= ') VALUES (';
		$sql .= ((int) $conf->entity);
		$sql .= ", '".$this->db->escape($this->ref)."'";
		$sql .= ", '".$this->db->escape($this->label)."'";
		$sql .= ', '.($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL');
		$sql .= ', '.($this->fk_socpeople > 0 ? (int) $this->fk_socpeople : 'NULL');
		$sql .= ', '.((int) $this->fk_pipeline);
		$sql .= ', '.((int) $stageId);
		$sql .= ', '.((float) $this->amount);
		$sql .= ', '.($this->date_close_expected > 0 ? "'".$this->db->idate($this->date_close_expected)."'" : 'NULL');
		$sql .= ', '.($this->fk_source > 0 ? (int) $this->fk_source : 'NULL');
		$sql .= ', '.((int) $this->fk_user_resp);
		$sql .= ", '".$this->db->escape((string) $this->description)."'";
		$sql .= ", 'open'";
		$sql .= ", '".$this->db->idate($now)."'";
		$sql .= ', '.((int) $user->id);
		$sql .= ')';
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id($this->db->prefix().'crm_opportunity');
		$this->fk_stage = $stageId;
		$this->status = 'open';
		$this->datec = $now;
		$this->addHistory($user, 0, $stageId);
		return $this->id;
	}

	/**
	 * Update the descriptive fields. The stage is changed only by moveToStage().
	 *
	 * @param User $user User saving the card
	 * @return int 1 if OK, -1 on error
	 */
	public function update(User $user): int
	{
		if ($this->id <= 0) {
			$this->error = 'CrmErrorDealNotLoaded';
			return -1;
		}
		$this->label = crmSingleLine(strip_tags((string) $this->label), 255);
		// Only a link that changes is checked: a user who may not read third parties can still edit the other fields.
		$resql = $this->db->query('SELECT fk_soc, fk_socpeople FROM '.$this->db->prefix().'crm_opportunity WHERE rowid = '.((int) $this->id));
		$stored = $resql ? $this->db->fetch_object($resql) : null;
		if (!$stored || !$this->validateLinks($user, (int) $stored->fk_soc, (int) $stored->fk_socpeople)) {
			if (!$stored) {
				$this->error = 'CrmErrorNotFound';
			}
			return -1;
		}
		$sql = 'UPDATE '.$this->db->prefix().'crm_opportunity SET';
		$sql .= " label = '".$this->db->escape($this->label)."'";
		$sql .= ', fk_soc = '.($this->fk_soc > 0 ? (int) $this->fk_soc : 'NULL');
		$sql .= ', fk_socpeople = '.($this->fk_socpeople > 0 ? (int) $this->fk_socpeople : 'NULL');
		$sql .= ', amount = '.((float) $this->amount);
		$sql .= ', date_close_expected = '.($this->date_close_expected > 0 ? "'".$this->db->idate($this->date_close_expected)."'" : 'NULL');
		$sql .= ', fk_source = '.($this->fk_source > 0 ? (int) $this->fk_source : 'NULL');
		$sql .= ', fk_user_resp = '.((int) $this->fk_user_resp);
		$sql .= ", description = '".$this->db->escape((string) $this->description)."'";
		$sql .= ' WHERE rowid = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * The third party and the contact of the deal must exist in this entity, the user must be allowed to read third parties,
	 * and the contact must belong to the third party. A value equal to the stored one is not checked again.
	 *
	 * @param User $user           User saving the deal
	 * @param int  $currentSoc     Third party already stored (0 for a new deal)
	 * @param int  $currentContact Contact already stored (0 for a new deal)
	 * @return bool False with a language key in ->error
	 */
	private function validateLinks(User $user, int $currentSoc, int $currentContact): bool
	{
		$prefix = $this->db->prefix();
		$soc = (int) $this->fk_soc;
		$contact = (int) $this->fk_socpeople;
		if ($soc > 0 && $soc !== $currentSoc) {
			$resql = $this->db->query('SELECT rowid FROM '.$prefix.'societe WHERE rowid = '.$soc.' AND entity IN ('.getEntity('societe').')');
			if (!$user->hasRight('societe', 'lire') || !$resql || $this->db->num_rows($resql) === 0) {
				$this->error = 'CrmErrorThirdPartyInvalid';
				return false;
			}
		}
		if ($contact > 0 && ($contact !== $currentContact || $soc !== $currentSoc)) {
			$resql = $this->db->query('SELECT rowid FROM '.$prefix.'socpeople WHERE rowid = '.$contact.' AND fk_soc = '.$soc.' AND entity IN ('.getEntity('societe').')');
			if ($soc <= 0 || !$resql || $this->db->num_rows($resql) === 0) {
				$this->error = 'CrmErrorContactInvalid';
				return false;
			}
		}
		return true;
	}

	/**
	 * Move the deal to another stage. Open-to-open moves can require a future agenda event.
	 * Won and lost do not. A lost move requires a loss reason.
	 *
	 * @param User   $user         User moving the card
	 * @param int    $stageId      Target stage
	 * @param int    $lossReasonId Required when the target stage is lost
	 * @param string $lossNote     Optional note
	 * @return int 1 if OK, -1 on error
	 */
	public function moveToStage(User $user, int $stageId, int $lossReasonId = 0, string $lossNote = ''): int
	{
		require_once __DIR__.'/stage.class.php';
		require_once __DIR__.'/crmactivity.class.php';
		if ($this->id <= 0) {
			$this->error = 'CrmErrorDealNotLoaded';
			return -1;
		}
		$stage = new Stage($this->db);
		if ($stage->fetch($stageId) <= 0 || (int) $stage->fk_pipeline !== (int) $this->fk_pipeline) {
			$this->error = 'CrmErrorUnknownStage';
			return -1;
		}
		if (!$stage->active && (int) $stage->id !== (int) $this->fk_stage) {
			$this->error = 'CrmErrorStageInactive';
			return -1;
		}
		$current = new Stage($this->db);
		$current->fetch((int) $this->fk_stage);
		$leavingOpen = ($current->id > 0 && !$current->isClosed());
		$enteringOpen = !$stage->isClosed();
		// Reopening a closed deal is a move to an open stage like any other: it needs a next step too.
		if ($enteringOpen && ($leavingOpen || ($current->id > 0 && $current->isClosed())) && getDolGlobalInt('CRM_REQUIRE_NEXT_STEP') && !CrmActivity::hasFuture($this->db, (int) $this->id)) {
			$this->error = 'CrmErrorNextStepRequired';
			return -1;
		}
		if ($stage->is_lost && $lossReasonId <= 0) {
			$this->error = 'CrmErrorLossReasonRequired';
			return -1;
		}
		$from = (int) $this->fk_stage;
		$sql = 'UPDATE '.$this->db->prefix().'crm_opportunity SET fk_stage = '.((int) $stageId);
		if ($stage->is_won) {
			$sql .= ", status = 'won', date_close = '".$this->db->idate(dol_now())."'";
			$sql .= ', fk_loss_reason = '.($lossReasonId > 0 ? (int) $lossReasonId : 'NULL');
			$sql .= ", loss_note = '".$this->db->escape($lossNote)."'";
		} elseif ($stage->is_lost) {
			$sql .= ", status = 'lost', date_close = '".$this->db->idate(dol_now())."'";
			$sql .= ', fk_loss_reason = '.((int) $lossReasonId);
			$sql .= ", loss_note = '".$this->db->escape($lossNote)."'";
		} else {
			$sql .= ", status = 'open', date_close = NULL, fk_loss_reason = NULL";
		}
		$sql .= ' WHERE rowid = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$this->fk_stage = $stageId;
		$this->status = $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open');
		if ($from !== $stageId) {
			$this->addHistory($user, $from, $stageId);
		}
		return 1;
	}

	/**
	 * The prospect replied: move the deal to the reply stage of its pipeline ("Replied to outreach"), so that it shows on the
	 * board as a lead that answered and waits for qualification. Only an open deal that is before that stage moves: one that
	 * is already at or beyond it, or closed, stays where it is. This is the module reacting to an event, not a salesperson
	 * dragging a card, so the rule "a next step is required to change stage" does not apply. Off when the setting
	 * CRM_REPLY_MOVES_DEAL is 0.
	 *
	 * @param User $user User recorded in the stage history (the collector's, or the one who marked the reply)
	 * @return int 1 if moved, 0 if nothing to do (no reply stage, setting off, deal already there or beyond, closed), -1 on error
	 */
	public function moveToReplyStage(User $user): int
	{
		require_once __DIR__.'/stage.class.php';
		if ($this->id <= 0 || !getDolGlobalInt('CRM_REPLY_MOVES_DEAL', 1) || !crmStageReplySupported($this->db)) {
			return 0;
		}
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'crm_stage WHERE fk_pipeline = '.((int) $this->fk_pipeline).' AND is_reply = 1 AND active = 1';
		$resql = $this->db->query($sql);
		$row = $resql ? $this->db->fetch_object($resql) : null;
		if (!$row) {
			return 0;
		}
		$target = new Stage($this->db);
		$current = new Stage($this->db);
		if ($target->fetch((int) $row->rowid) <= 0 || $current->fetch((int) $this->fk_stage) <= 0) {
			return 0;
		}
		if ((string) $this->status !== 'open' || $current->isClosed() || !crmStageComesBefore((int) $current->position, (int) $current->id, (int) $target->position, (int) $target->id)) {
			return 0;
		}
		$update = 'UPDATE '.$this->db->prefix().'crm_opportunity SET fk_stage = '.((int) $target->id).' WHERE rowid = '.((int) $this->id);
		if (!$this->db->query($update)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$from = (int) $this->fk_stage;
		$this->fk_stage = (int) $target->id;
		$this->addHistory($user, $from, (int) $target->id);
		return 1;
	}

	/**
	 * Delete the deal and its module rows. Agenda events stay.
	 *
	 * @param User $user User deleting
	 * @return int 1 if OK, -1 on error
	 */
	public function delete(User $user): int
	{
		if ($this->id <= 0) {
			return -1;
		}
		$id = (int) $this->id;
		$prefix = $this->db->prefix();
		$ok = true;
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_opportunity_qualification WHERE fk_opportunity = '.$id);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_opportunity_propal WHERE fk_opportunity = '.$id);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_stage_history WHERE fk_opportunity = '.$id);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_sequence_log WHERE fk_enrol IN (SELECT rowid FROM '.$prefix.'crm_sequence_enrol WHERE fk_opportunity = '.$id.')');
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_sequence_enrol WHERE fk_opportunity = '.$id);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_opportunity WHERE rowid = '.$id);
		if (!$ok) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * Link an existing proposal. The deal amount is not copied from the proposal.
	 *
	 * @param int $propalId Proposal rowid
	 * @return int 1 if OK, -1 on error
	 */
	public function linkProposal(User $user, int $propalId): int
	{
		global $conf;
		if ($this->id <= 0 || $propalId <= 0) {
			return -1;
		}
		// The proposal must exist in this entity, be readable by the user and belong to the third party of the deal:
		// a link shows the proposal reference and total on the deal.
		$resql = $this->db->query('SELECT fk_soc FROM '.$this->db->prefix().'propal WHERE rowid = '.((int) $propalId).' AND entity IN ('.getEntity('propal').')');
		$propal = $resql ? $this->db->fetch_object($resql) : null;
		if (!isModEnabled('propal') || !$user->hasRight('propal', 'lire') || !$propal || (int) $this->fk_soc <= 0 || (int) $propal->fk_soc !== (int) $this->fk_soc) {
			$this->error = 'CrmErrorProposalInvalid';
			return -1;
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_opportunity_propal (entity, fk_opportunity, fk_propal) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $this->id).', '.((int) $propalId).')';
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * Remove one proposal link.
	 *
	 * @param int $propalId Proposal rowid
	 * @return int 1 if OK, -1 on error
	 */
	public function unlinkProposal(int $propalId): int
	{
		$sql = 'DELETE FROM '.$this->db->prefix().'crm_opportunity_propal WHERE fk_opportunity = '.((int) $this->id).' AND fk_propal = '.((int) $propalId);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * Columns a list may be sorted on, by the name the pages use.
	 *
	 * @var array<string,string>
	 */
	private const SORT_COLUMNS = array(
		'ref' => 'o.ref',
		'label' => 'o.label',
		'account' => 's.nom',
		'contact' => 'sp.lastname',
		'stage' => 'st.position',
		'amount' => 'o.amount',
		'close' => 'o.date_close_expected',
		'owner' => 'u.lastname',
		'next' => 'next_date',
	);

	/**
	 * FROM and WHERE of the deal list, shared by the list and its count.
	 */
	private function listFromWhere(User $user, string $status, int $pipelineId, int $ownerId, string $search): string
	{
		global $conf;
		$sql = ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'societe as s ON s.rowid = o.fk_soc';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'socpeople as sp ON sp.rowid = o.fk_socpeople';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_stage as st ON st.rowid = o.fk_stage';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = o.fk_user_resp';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_source as src ON src.rowid = o.fk_source';
		$sql .= ' WHERE o.entity = '.((int) $conf->entity);
		$sql .= crmOwnerSql($user, 'o');
		if ($status !== '') {
			$sql .= " AND o.status = '".$this->db->escape($status)."'";
		}
		if ($pipelineId > 0) {
			$sql .= ' AND o.fk_pipeline = '.((int) $pipelineId);
		}
		if ($ownerId > 0 && crmCanReadOwner($user, $ownerId)) {
			$sql .= ' AND o.fk_user_resp = '.((int) $ownerId);
		}
		if ($search !== '') {
			$like = '%'.$this->db->escape($this->db->escapeforlike($search)).'%';
			$sql .= " AND (o.ref LIKE '".$like."' OR o.label LIKE '".$like."' OR s.nom LIKE '".$like."'";
			$sql .= " OR sp.lastname LIKE '".$like."' OR sp.firstname LIKE '".$like."' OR sp.email LIKE '".$like."')";
		}
		return $sql;
	}

	/**
	 * How many deals the same filters select.
	 */
	public function countList(User $user, string $status = '', int $pipelineId = 0, int $ownerId = 0, string $search = ''): int
	{
		$sql = 'SELECT COUNT(o.rowid) as nb'.$this->listFromWhere($user, $status, $pipelineId, $ownerId, $search);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
		return 0;
	}

	/**
	 * Deals visible to the user.
	 *
	 * @param User   $user       Caller
	 * @param string $status     open|won|lost or empty for every status
	 * @param int    $pipelineId Pipeline filter, 0 for all
	 * @param int    $ownerId    Owner filter, ignored when the caller cannot see that owner
	 * @param string $search     Fragment of the ref, title, account name, or contact name or e-mail
	 * @param int    $limit      0 keeps every row
	 * @param int    $offset     Rows to skip, with a limit
	 * @param string $sort       ref, label, account, contact, stage, amount, close, owner or next; anything else sorts on ref
	 * @param string $order      ASC or DESC
	 * @return array<int,object>
	 */
	public function fetchList(User $user, string $status = '', int $pipelineId = 0, int $ownerId = 0, string $search = '', int $limit = 0, int $offset = 0, string $sort = 'ref', string $order = 'DESC'): array
	{
		$sql = 'SELECT o.rowid, o.ref, o.label, o.amount, o.status, o.date_close_expected, o.datec, o.fk_soc, o.fk_socpeople, o.fk_stage, o.fk_pipeline, o.fk_user_resp, o.fk_source,';
		$sql .= ' s.nom as soc_name, sp.firstname, sp.lastname, sp.email as contact_email,';
		$sql .= ' st.label as stage_label, st.color, st.probability, st.rotting_days, st.is_won, st.is_lost, st.forecast_category,';
		$sql .= ' u.firstname as owner_firstname, u.lastname as owner_lastname, src.label as source_label,';
		$sql .= ' (SELECT MIN(a.datep) FROM '.$this->db->prefix().'actioncomm as a';
		$sql .= " WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND a.fk_element = o.rowid AND a.percent < 100) as next_date";
		$sql .= $this->listFromWhere($user, $status, $pipelineId, $ownerId, $search);
		$column = self::SORT_COLUMNS[$sort] ?? 'o.ref';
		$sql .= ' ORDER BY '.$column.' '.(strtoupper($order) === 'ASC' ? 'ASC' : 'DESC').', o.rowid DESC';
		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, max(0, $offset));
		}
		$list = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = $obj;
			}
		}
		return $list;
	}

	/**
	 * Proposals linked to this deal. Empty when the proposal module is off.
	 *
	 * @return array<int,object>
	 */
	public function fetchProposals(): array
	{
		if (!isModEnabled('propal') || $this->id <= 0) {
			return array();
		}
		$sql = 'SELECT p.rowid, p.ref, p.total_ht, p.fk_statut';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity_propal as l';
		$sql .= ' INNER JOIN '.$this->db->prefix().'propal as p ON p.rowid = l.fk_propal';
		$sql .= ' WHERE l.fk_opportunity = '.((int) $this->id).' AND p.entity IN ('.getEntity('propal').')';
		$sql .= ' ORDER BY p.ref ASC';
		$list = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = $obj;
			}
		}
		return $list;
	}

	/**
	 * Stage moves, oldest first.
	 *
	 * @return array<int,object>
	 */
	public function fetchHistory(): array
	{
		$sql = 'SELECT h.rowid, h.fk_stage_from, h.fk_stage_to, h.datec, h.fk_user, sf.label as from_label, st.label as to_label, u.firstname, u.lastname';
		$sql .= ' FROM '.$this->db->prefix().'crm_stage_history as h';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_stage as sf ON sf.rowid = h.fk_stage_from';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'crm_stage as st ON st.rowid = h.fk_stage_to';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'user as u ON u.rowid = h.fk_user';
		$sql .= ' WHERE h.fk_opportunity = '.((int) $this->id);
		$sql .= ' ORDER BY h.datec ASC, h.rowid ASC';
		$list = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = $obj;
			}
		}
		return $list;
	}

	/**
	 * @param User $user   User who moved the card
	 * @param int  $fromId Previous stage, 0 on creation
	 * @param int  $toId   New stage
	 */
	private function addHistory(User $user, int $fromId, int $toId): void
	{
		global $conf;
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_stage_history (entity, fk_opportunity, fk_stage_from, fk_stage_to, fk_user, datec) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $this->id).', ';
		$sql .= ($fromId > 0 ? (string) $fromId : 'NULL').', '.((int) $toId).', '.((int) $user->id).", '".$this->db->idate(dol_now())."')";
		$this->db->query($sql);
	}

	/**
	 * First active stage that is neither won nor lost.
	 */
	private function firstOpenStage(int $pipelineId): int
	{
		global $conf;
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'crm_stage';
		$sql .= ' WHERE entity = '.((int) $conf->entity).' AND fk_pipeline = '.((int) $pipelineId);
		$sql .= ' AND active = 1 AND is_won = 0 AND is_lost = 0';
		$sql .= ' ORDER BY position ASC';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}
}
