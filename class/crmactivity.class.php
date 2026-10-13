<?php
declare(strict_types=1);

/**
 * \file    class/crmactivity.class.php
 * \ingroup crm
 * \brief   Agenda events linked to a deal. This class has no table of its own.
 */

require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/cactioncomm.class.php';
require_once __DIR__.'/../lib/crm.lib.php';

/**
 * Wrapper around ActionComm for CRM next steps.
 */
class CrmActivity
{
	public const ELEMENT_TYPE = 'opportunity@crm';

	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Map a CRM step kind to an agenda type. Call and meeting need an active type and fall back to AC_OTH.
	 * E-mail kinds use AC_EMAIL and AC_EMAIL_IN whether or not the type is active, as Dolibarr core does when it
	 * logs a message sent from a proposal or an invoice: ActionComm looks the type up by code and the active flag
	 * only hides it from the manual pick-lists. They fall back to AC_OTH only when the type row does not exist.
	 *
	 * @param string $kind call|meeting|task|email|email_in
	 */
	public static function typeCode(DoliDB $db, string $kind): string
	{
		$wanted = 'AC_OTH';
		$needActive = true;
		if ($kind === 'call') {
			$wanted = 'AC_TEL';
		} elseif ($kind === 'meeting') {
			$wanted = 'AC_RDV';
		} elseif ($kind === 'email') {
			$wanted = 'AC_EMAIL';
			$needActive = false;
		} elseif ($kind === 'email_in') {
			$wanted = 'AC_EMAIL_IN';
			$needActive = false;
		}
		$actionType = new CActionComm($db);
		if ($actionType->fetch($wanted) > 0 && (!$needActive || (int) $actionType->active === 1)) {
			return $wanted;
		}
		return 'AC_OTH';
	}

	/**
	 * True for a planned e-mail step: an e-mail event that is not done. Its label is the subject and its note the
	 * suggested text, so it can be reviewed and sent from email_compose.php.
	 */
	public static function isPlannedEmail(?string $eventCode, int $percent): bool
	{
		return $eventCode === 'AC_EMAIL' && $percent < 100;
	}

	/**
	 * True when the deal has an unfinished event dated today or later.
	 */
	public static function hasFuture(DoliDB $db, int $opportunityId): bool
	{
		$today = crmTodayStart();
		$sql = 'SELECT id FROM '.$db->prefix().'actioncomm';
		$sql .= " WHERE elementtype = '".$db->escape(self::ELEMENT_TYPE)."'";
		$sql .= ' AND fk_element = '.((int) $opportunityId);
		$sql .= ' AND percent < 100';
		$sql .= " AND datep >= '".$db->idate($today)."'";
		$sql .= $db->plimit(1, 0);
		$resql = $db->query($sql);
		return ($resql && $db->num_rows($resql) > 0);
	}

	/**
	 * Timestamp of the latest done event, or 0.
	 */
	public static function latestDone(DoliDB $db, int $opportunityId): int
	{
		$sql = 'SELECT MAX(datep) as datep FROM '.$db->prefix().'actioncomm';
		$sql .= " WHERE elementtype = '".$db->escape(self::ELEMENT_TYPE)."'";
		$sql .= ' AND fk_element = '.((int) $opportunityId);
		$sql .= ' AND percent >= 100';
		$resql = $db->query($sql);
		if ($resql && ($obj = $db->fetch_object($resql)) && !empty($obj->datep)) {
			return (int) $db->jdate($obj->datep);
		}
		return 0;
	}

	/**
	 * Create an agenda event owned by the deal's salesperson.
	 *
	 * @param User   $user   User creating the event
	 * @param int    $opportunityId Deal id
	 * @param int    $socId  Third party
	 * @param int    $contactId Primary contact
	 * @param int    $ownerId Assigned user
	 * @param string $kind   call|meeting|task|email
	 * @param string $label  Event label
	 * @param int    $datep  Start timestamp
	 * @param int    $percentage 0 for a next step, 100 for a logged send
	 * @param string $note   Text kept on the event. For an e-mail step it is the suggested message.
	 * @return int Event id, or -1
	 */
	public function create(User $user, int $opportunityId, int $socId, int $contactId, int $ownerId, string $kind, string $label, int $datep, int $percentage = 0, string $note = ''): int
	{
		$event = new ActionComm($this->db);
		$event->type_code = self::typeCode($this->db, $kind);
		$event->label = $label;
		if ($note !== '') {
			$event->note_private = $note;
		}
		$event->datep = $datep;
		$event->datef = $datep + 3600;
		$event->percentage = $percentage;
		$event->socid = $socId;
		$event->userownerid = $ownerId;
		$event->elementtype = self::ELEMENT_TYPE;
		$event->elementid = $opportunityId;
		$event->fk_element = $opportunityId;
		if ($contactId > 0) {
			$event->contact_id = $contactId;
			$event->socpeopleassigned = array($contactId => array('id' => $contactId, 'mandatory' => 0, 'answer_status' => 0, 'transparency' => 0));
		}
		$result = $event->create($user);
		if ($result < 0) {
			$this->error = $event->error;
			return -1;
		}
		return (int) $event->id;
	}

	/**
	 * Record one e-mail of the exchange with the customer as a done agenda event on the deal.
	 *
	 * The event is done (100) on purpose: a logged message is history, not a next step, and an event
	 * that is not done and dated today or later would count as one in hasFuture().
	 *
	 * @param User                $user      User creating the event
	 * @param int                 $opportunityId Deal id
	 * @param int                 $socId     Third party
	 * @param int                 $contactId Primary contact
	 * @param int                 $ownerId   Assigned user
	 * @param string              $direction out for a message we sent, in for a message we received
	 * @param string              $subject   Subject
	 * @param string              $body      Plain-text body
	 * @param int                 $datep     Date of the message
	 * @param array<string,string> $meta     msgid, from, to, cc
	 * @return int Event id, 0 when this Message-ID is already recorded, -1 on error
	 */
	public function createEmail(User $user, int $opportunityId, int $socId, int $contactId, int $ownerId, string $direction, string $subject, string $body, int $datep, array $meta = array()): int
	{
		$msgid = (string) ($meta['msgid'] ?? '');
		if ($msgid !== '') {
			$existing = new ActionComm($this->db);
			if ($existing->fetch(0, '', '', $msgid) > 0) {
				return 0;
			}
		}
		global $langs;
		crmLoadLangs();
		$incoming = ($direction === 'in');
		$event = new ActionComm($this->db);
		$event->type_code = self::typeCode($this->db, $incoming ? 'email_in' : 'email');
		$event->code = $incoming ? 'AC_EMAIL_IN' : 'AC_EMAIL';
		$event->label = $langs->transnoentities($incoming ? 'CrmEmailReceived' : 'CrmEmailSent').' - '.$subject;
		$event->note_private = $body;
		$event->datep = $datep;
		$event->datef = $datep;
		$event->percentage = 100;
		$event->socid = $socId;
		$event->userownerid = $ownerId;
		$event->elementtype = self::ELEMENT_TYPE;
		$event->elementid = $opportunityId;
		$event->fk_element = $opportunityId;
		$event->email_msgid = $msgid;
		$event->email_from = (string) ($meta['from'] ?? '');
		$event->email_to = (string) ($meta['to'] ?? '');
		$event->email_tocc = (string) ($meta['cc'] ?? '');
		$event->email_subject = $subject;
		if ($contactId > 0) {
			$event->contact_id = $contactId;
			$event->socpeopleassigned = array($contactId => array('id' => $contactId, 'mandatory' => 0, 'answer_status' => 0, 'transparency' => 0));
		}
		if ($event->create($user) < 0) {
			$this->error = $event->error;
			return -1;
		}
		return (int) $event->id;
	}

	/**
	 * Mark one CRM event done. Events that are not linked to a deal are refused.
	 *
	 * @param User $user User completing the step
	 * @param int  $eventId Agenda event id
	 * @return int 1 if OK, -1 on error
	 */
	public function complete(User $user, int $eventId): int
	{
		$event = new ActionComm($this->db);
		if ($event->fetch($eventId) <= 0) {
			$this->error = 'Event not found';
			return -1;
		}
		if ((string) $event->elementtype !== self::ELEMENT_TYPE) {
			$this->error = 'Event is not a CRM step';
			return -1;
		}
		$event->percentage = 100;
		if ($event->update($user) < 0) {
			$this->error = $event->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Events of one deal, newest first.
	 *
	 * @return array<int,object>
	 */
	public function fetchForOpportunity(int $opportunityId): array
	{
		$sql = 'SELECT a.id as rowid, a.label, a.datep, a.percent, a.fk_user_action, a.code as event_code, c.code, c.libelle as type_label';
		$sql .= ' FROM '.$this->db->prefix().'actioncomm as a';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'c_actioncomm as c ON c.id = a.fk_action';
		$sql .= " WHERE a.elementtype = '".$this->db->escape(self::ELEMENT_TYPE)."'";
		$sql .= ' AND a.fk_element = '.((int) $opportunityId);
		$sql .= ' ORDER BY a.datep DESC';
		return $this->fetchRows($sql);
	}

	/**
	 * Unfinished CRM events visible to the user.
	 *
	 * @param string $scope overdue|today|planned|done
	 * @param int    $forceOwner When set, ignore readall and keep this owner only
	 * @return array<int,object>
	 */
	public function fetchList(User $user, string $scope, int $forceOwner = 0): array
	{
		global $conf;
		$today = crmTodayStart();
		$tomorrow = $today + 86400;
		$sql = 'SELECT a.id as rowid, a.label, a.datep, a.percent, a.fk_user_action, a.fk_element, a.code as event_code, o.ref, o.label as deal_label, o.fk_soc, o.fk_user_resp, s.nom as soc_name';
		$sql .= ' FROM '.$this->db->prefix().'actioncomm as a';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = a.fk_element';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'societe as s ON s.rowid = o.fk_soc';
		$sql .= " WHERE a.elementtype = '".$this->db->escape(self::ELEMENT_TYPE)."'";
		$sql .= ' AND o.entity = '.((int) $conf->entity);
		if ($forceOwner > 0) {
			$sql .= ' AND o.fk_user_resp = '.((int) $forceOwner);
		} else {
			$sql .= crmOwnerSql($user, 'o');
		}
		if ($scope === 'done') {
			$sql .= ' AND a.percent >= 100';
		} elseif ($scope === 'overdue') {
			$sql .= ' AND a.percent < 100 AND a.datep < \''.$this->db->idate($today).'\'';
		} elseif ($scope === 'today') {
			$sql .= ' AND a.percent < 100 AND a.datep >= \''.$this->db->idate($today).'\' AND a.datep < \''.$this->db->idate($tomorrow).'\'';
		} else {
			$sql .= ' AND a.percent < 100 AND a.datep >= \''.$this->db->idate($today).'\'';
		}
		$sql .= ' ORDER BY a.datep ASC';
		return $this->fetchRows($sql);
	}

	/**
	 * @param string $sql Query
	 * @return array<int,object>
	 */
	private function fetchRows(string $sql): array
	{
		$rows = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = $obj;
			}
		}
		return $rows;
	}
}
