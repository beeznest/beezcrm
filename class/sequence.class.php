<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/sequence.class.php
 * \ingroup crm
 * \brief   A customer cadence. Steps are counted from the enrolment date.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';
require_once __DIR__.'/opportunity.class.php';

/**
 * Email / call / task sequence.
 */
class Sequence
{
	/** @var DoliDB */
	public $db;
	/** @var int */
	public $id = 0;
	/** @var string */
	public $ref = '';
	/** @var string */
	public $label = '';
	/** @var int */
	public $active = 1;
	/** @var int */
	public $stop_on_reply = 1;
	/** @var int */
	public $stop_on_won = 1;
	/** @var int */
	public $stop_on_lost = 1;
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
	 * @param int $id Sequence id
	 * @return int 1 if found, 0 if not, -1 on error
	 */
	public function fetch(int $id): int
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_sequence WHERE rowid = '.((int) $id);
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
		$this->ref = (string) $obj->ref;
		$this->label = (string) $obj->label;
		$this->active = (int) $obj->active;
		$this->stop_on_reply = (int) $obj->stop_on_reply;
		$this->stop_on_won = (int) $obj->stop_on_won;
		$this->stop_on_lost = (int) $obj->stop_on_lost;
		return 1;
	}

	/**
	 * @return array<int,object>
	 */
	public function fetchAll(): array
	{
		global $conf;
		$sql = 'SELECT s.*, (SELECT COUNT(e.rowid) FROM '.$this->db->prefix().'crm_sequence_enrol as e WHERE e.fk_sequence = s.rowid) as nb_enrol';
		$sql .= ', (SELECT COUNT(st.rowid) FROM '.$this->db->prefix().'crm_sequence_step as st WHERE st.fk_sequence = s.rowid) as nb_step';
		$sql .= ' FROM '.$this->db->prefix().'crm_sequence as s WHERE s.entity = '.((int) $conf->entity);
		$sql .= ' ORDER BY s.ref ASC';
		return $this->rows($sql);
	}

	/**
	 * @param User $user Creator
	 * @return int Row id, or -1
	 */
	public function create(User $user): int
	{
		global $conf;
		$this->ref = strtoupper((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $this->ref));
		if (strlen($this->ref) > 32) {
			$this->error = 'CrmErrorSequenceRefInvalid';
			return -1;
		}
		$this->label = crmSingleLine(strip_tags($this->label), 255);
		if ($this->ref === '') {
			$this->ref = crmNextSequenceRef($this->db);
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_sequence (entity, ref, label, active, stop_on_reply, stop_on_won, stop_on_lost, datec, fk_user_creat) VALUES (';
		$sql .= ((int) $conf->entity).", '".$this->db->escape($this->ref)."', '".$this->db->escape($this->label)."', ".((int) $this->active);
		$sql .= ', '.((int) $this->stop_on_reply).', '.((int) $this->stop_on_won).', '.((int) $this->stop_on_lost);
		$sql .= ", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		$this->id = (int) $this->db->last_insert_id($this->db->prefix().'crm_sequence');
		return $this->id;
	}

	/**
	 * Save the reference, the label, the active flag and the stop rules of a loaded sequence. Steps and enrolments are
	 * not touched: a running enrolment goes on, and one on an inactive sequence waits until it is active again.
	 *
	 * @return int 1 if OK, -1 on error with a language key in ->error
	 */
	public function update(): int
	{
		global $conf;
		if ($this->id <= 0) {
			$this->error = 'CrmErrorSequenceNotFound';
			return -1;
		}
		$this->label = crmSingleLine(strip_tags($this->label), 255);
		if ($this->label === '') {
			$this->error = 'CrmErrorSequenceLabelRequired';
			return -1;
		}
		$this->ref = strtoupper((string) preg_replace('/[^A-Za-z0-9_\-]/', '', $this->ref));
		if ($this->ref === '' || strlen($this->ref) > 32) {
			$this->error = 'CrmErrorSequenceRefInvalid';
			return -1;
		}
		$sqlDup = 'SELECT rowid FROM '.$this->db->prefix().'crm_sequence WHERE entity = '.((int) $conf->entity);
		$sqlDup .= " AND ref = '".$this->db->escape($this->ref)."' AND rowid <> ".((int) $this->id);
		$resDup = $this->db->query($sqlDup);
		if ($resDup && $this->db->num_rows($resDup) > 0) {
			$this->error = 'CrmErrorSequenceRefExists';
			return -1;
		}
		$sql = 'UPDATE '.$this->db->prefix()."crm_sequence SET ref = '".$this->db->escape($this->ref)."', label = '".$this->db->escape($this->label)."'";
		$sql .= ', active = '.($this->active ? 1 : 0).', stop_on_reply = '.($this->stop_on_reply ? 1 : 0);
		$sql .= ', stop_on_won = '.($this->stop_on_won ? 1 : 0).', stop_on_lost = '.($this->stop_on_lost ? 1 : 0);
		$sql .= ' WHERE rowid = '.((int) $this->id).' AND entity = '.((int) $conf->entity);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * @return array<int,object>
	 */
	public function fetchSteps(): array
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_sequence_step WHERE fk_sequence = '.((int) $this->id).' ORDER BY position ASC, rowid ASC';
		return $this->rows($sql);
	}

	/**
	 * Running enrolments whose next step is this one. Deleting the step ends them, as there is nothing left to run.
	 */
	public function countWaitingOnStep(int $stepId): int
	{
		$step = $this->fetchStep($stepId);
		if ($step === null) {
			return 0;
		}
		$sql = 'SELECT COUNT(rowid) as nb FROM '.$this->db->prefix().'crm_sequence_enrol WHERE fk_sequence = '.((int) $this->id);
		$sql .= " AND status = 'running' AND current_step = ".((int) $step->position);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
		return 0;
	}

	/**
	 * @param string $action   email|call|task
	 * @param string $sendMode For an e-mail step: draft or send to force its mode, empty to follow the global setting
	 * @return int Row id, or -1
	 */
	public function addStep(string $action, int $delayDays, string $subject, string $body, string $sendMode = ''): int
	{
		$subject = crmSingleLine(strip_tags($subject), 255);
		if ($action !== 'email' || !in_array($sendMode, array('draft', 'send'), true)) {
			$sendMode = '';
		}
		$sqlPos = 'SELECT MAX(position) as mx FROM '.$this->db->prefix().'crm_sequence_step WHERE fk_sequence = '.((int) $this->id);
		$resPos = $this->db->query($sqlPos);
		$position = 1;
		if ($resPos && ($objPos = $this->db->fetch_object($resPos)) && $objPos->mx !== null) {
			$position = ((int) $objPos->mx) + 1;
		}
		$withMode = crmStepModeSupported($this->db);
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_sequence_step (fk_sequence, position, action, delay_days, subject, body'.($withMode ? ', send_mode' : '').') VALUES (';
		$sql .= ((int) $this->id).', '.$position.", '".$this->db->escape($action)."', ".((int) $delayDays);
		$sql .= ", '".$this->db->escape($subject)."', '".$this->db->escape($body)."'".($withMode ? ", '".$this->db->escape($sendMode)."'" : '').')';
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return (int) $this->db->last_insert_id($this->db->prefix().'crm_sequence_step');
	}

	/**
	 * One step of this sequence.
	 *
	 * @return object|null
	 */
	public function fetchStep(int $stepId)
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_sequence_step WHERE rowid = '.((int) $stepId).' AND fk_sequence = '.((int) $this->id);
		$resql = $this->db->query($sql);
		return ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj : null;
	}

	/**
	 * Change a step of this sequence. The step keeps its position. Enrolments are not touched: one that waits for this step
	 * keeps the due date already computed, so a new delay applies to the steps that are scheduled from now on. The text,
	 * the subject and the mode are read when the step runs, so they apply to the next run of every enrolment.
	 *
	 * @param string $action   email|call|task
	 * @param string $sendMode For an e-mail step: draft or send to force its mode, empty to follow the global setting
	 * @return int 1 if OK, -1 on error with a language key in ->error
	 */
	public function updateStep(int $stepId, string $action, int $delayDays, string $subject, string $body, string $sendMode = ''): int
	{
		$subject = crmSingleLine(strip_tags($subject), 255);
		if ($this->fetchStep($stepId) === null) {
			$this->error = 'CrmErrorStepNotFound';
			return -1;
		}
		if (!in_array($action, array('email', 'call', 'task'), true)) {
			$this->error = 'CrmErrorStepKind';
			return -1;
		}
		if ($action !== 'email' || !in_array($sendMode, array('draft', 'send'), true)) {
			$sendMode = '';
		}
		if ($action !== 'email') {
			$body = '';
		}
		$sql = 'UPDATE '.$this->db->prefix().'crm_sequence_step SET';
		$sql .= " action = '".$this->db->escape($action)."', delay_days = ".max(0, (int) $delayDays);
		$sql .= ", subject = '".$this->db->escape($subject)."', body = '".$this->db->escape($body)."'";
		if (crmStepModeSupported($this->db)) {
			$sql .= ", send_mode = '".$this->db->escape($sendMode)."'";
		}
		$sql .= ' WHERE rowid = '.((int) $stepId).' AND fk_sequence = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * @return int 1 if deleted, -1 on error
	 */
	public function deleteStep(int $stepId): int
	{
		$sql = 'DELETE FROM '.$this->db->prefix().'crm_sequence_step WHERE rowid = '.((int) $stepId).' AND fk_sequence = '.((int) $this->id);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * From v1.2.0. What an administrator deletes with an outreach, for the confirmation: its steps, its enrolments and the lines of
	 * its journal.
	 *
	 * @return array{steps:int,enrolments:int,running:int,journal:int}
	 */
	public function countContent(): array
	{
		$prefix = $this->db->prefix();
		$id = (int) $this->id;
		$one = function (string $sql): int {
			$resql = $this->db->query($sql);
			return ($resql && ($obj = $this->db->fetch_object($resql))) ? (int) $obj->nb : 0;
		};
		return array(
			'steps' => $one('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_step WHERE fk_sequence = '.$id),
			'enrolments' => $one('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_enrol WHERE fk_sequence = '.$id),
			'running' => $one('SELECT COUNT(*) as nb FROM '.$prefix."crm_sequence_enrol WHERE fk_sequence = ".$id." AND status = 'running'"),
			'journal' => $one('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_log WHERE fk_enrol IN (SELECT rowid FROM '.$prefix.'crm_sequence_enrol WHERE fk_sequence = '.$id.')'),
		);
	}

	/**
	 * From v1.2.0. Delete an outreach completely, for the cleaning of tests: its journal, its enrolments, its steps and itself. Only
	 * a user who may configure the CRM can. The agenda events that the outreach made on the deals (drafts, e-mails sent, tasks) are
	 * agenda events and stay, like every event of the module.
	 *
	 * @return int 1 if deleted, -1 with a language key in ->error
	 */
	public function deleteCompletely(User $user): int
	{
		global $conf;
		if ($this->id <= 0 || !$user->hasRight('crm', 'setup')) {
			$this->error = 'CrmErrorSequenceDeleteRights';
			return -1;
		}
		$prefix = $this->db->prefix();
		$id = (int) $this->id;
		$this->db->begin();
		$ok = $this->db->query('DELETE FROM '.$prefix.'crm_sequence_log WHERE fk_enrol IN (SELECT rowid FROM '.$prefix.'crm_sequence_enrol WHERE fk_sequence = '.$id.')');
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_sequence_enrol WHERE fk_sequence = '.$id);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_sequence_step WHERE fk_sequence = '.$id);
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_sequence WHERE rowid = '.$id.' AND entity = '.((int) $conf->entity));
		if (!$ok) {
			$this->db->rollback();
			$this->error = crmDbError($this->db);
			return -1;
		}
		$this->db->commit();
		dol_syslog('CRM outreach '.$id.' deleted completely by user '.((int) $user->id), LOG_INFO);
		return 1;
	}

	/**
	 * From v1.2.0. Delete one enrolment of this outreach with its journal lines (cleaning of a test on a deal). Only a user who may
	 * configure the CRM can.
	 *
	 * @return int 1 if deleted, -1 with a language key in ->error
	 */
	public function deleteEnrolment(User $user, int $enrolId): int
	{
		if (!$user->hasRight('crm', 'setup')) {
			$this->error = 'CrmErrorSequenceDeleteRights';
			return -1;
		}
		if (!$this->enrolmentVisible($user, $enrolId, (int) $this->id)) {
			$this->error = 'CrmErrorEnrolmentNotFound';
			return -1;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();
		$ok = $this->db->query('DELETE FROM '.$prefix.'crm_sequence_log WHERE fk_enrol = '.((int) $enrolId));
		$ok = $ok && $this->db->query('DELETE FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.((int) $enrolId).' AND fk_sequence = '.((int) $this->id));
		if (!$ok) {
			$this->db->rollback();
			$this->error = crmDbError($this->db);
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Every enrolment of one deal, newest first, with the outreach and the step it waits for.
	 *
	 * @return array<int,object>
	 */
	public function fetchEnrolmentsForOpportunity(int $opportunityId): array
	{
		global $conf;
		$prefix = $this->db->prefix();
		$sql = 'SELECT e.*, s.ref as seq_ref, s.label as seq_label, st.action as step_action, st.subject as step_subject';
		$sql .= ' FROM '.$prefix.'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$prefix.'crm_sequence as s ON s.rowid = e.fk_sequence';
		$sql .= ' LEFT JOIN '.$prefix.'crm_sequence_step as st ON st.fk_sequence = e.fk_sequence AND st.position = e.current_step';
		$sql .= ' WHERE e.fk_opportunity = '.((int) $opportunityId).' AND e.entity = '.((int) $conf->entity).' ORDER BY e.date_enrol DESC, e.rowid DESC';
		return $this->rows($sql);
	}

	/**
	 * True when the deal follows an outreach now.
	 */
	public function hasRunningEnrolment(int $opportunityId): bool
	{
		$sql = 'SELECT rowid FROM '.$this->db->prefix()."crm_sequence_enrol WHERE fk_opportunity = ".((int) $opportunityId)." AND status = 'running'";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * The outreach a deal can be enrolled in: active ones that have at least one step.
	 *
	 * @return array<int,object>
	 */
	public function fetchEnrollable(): array
	{
		$list = array();
		foreach ($this->fetchAll() as $row) {
			if ((int) $row->active === 1 && (int) $row->nb_step > 0) {
				$list[] = $row;
			}
		}
		return $list;
	}

	/**
	 * Enrol a deal from a deal card. The rules live in enrol(); this name is kept for the callers that read better with it.
	 *
	 * @return int Enrolment id, or -1 with a language key in ->error
	 */
	public function enrolFromDeal(int $opportunityId): int
	{
		return $this->enrol($opportunityId);
	}

	/**
	 * Enrol a deal. Every entry (deal card, outreach card, MCP tool) goes through here, so the rules are the same everywhere:
	 * the outreach exists, belongs to this entity, is active and has a step; the deal exists in this entity, is open and does
	 * not already follow an outreach. The first step is scheduled from now plus its delay.
	 *
	 * @return int Enrolment id, or -1 with a language key in ->error
	 */
	public function enrol(int $opportunityId): int
	{
		global $conf;
		if ($this->id <= 0) {
			$this->error = 'CrmErrorSequenceNotFound';
			return -1;
		}
		if (!$this->active) {
			$this->error = 'CrmErrorSequenceInactive';
			return -1;
		}
		$steps = $this->fetchSteps();
		if (count($steps) === 0) {
			$this->error = 'CrmErrorSequenceNoSteps';
			return -1;
		}
		$resql = $this->db->query('SELECT status FROM '.$this->db->prefix().'crm_opportunity WHERE rowid = '.((int) $opportunityId).' AND entity = '.((int) $conf->entity));
		$deal = $resql ? $this->db->fetch_object($resql) : null;
		if (!$deal) {
			$this->error = 'CrmErrorNotFound';
			return -1;
		}
		if ((string) $deal->status !== 'open') {
			$this->error = 'CrmErrorDealClosed';
			return -1;
		}
		if ($this->hasRunningEnrolment($opportunityId)) {
			$this->error = 'CrmErrorDealRunning';
			return -1;
		}
		$first = $steps[0];
		$now = dol_now();
		$next = crmDueTimestamp($now, (int) $first->delay_days);
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_sequence_enrol (entity, fk_sequence, fk_opportunity, date_enrol, current_step, date_next, status) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $this->id).', '.((int) $opportunityId).", '".$this->db->idate($now)."', ".((int) $first->position);
		$sql .= ", '".$this->db->idate($next)."', 'running')";
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return (int) $this->db->last_insert_id($this->db->prefix().'crm_sequence_enrol');
	}

	/**
	 * True when the user may act on this enrolment: it belongs to the outreach (when one is given), to this entity, and its
	 * deal is one the user may read.
	 */
	public function enrolmentVisible(User $user, int $enrolId, int $sequenceId = 0): bool
	{
		global $conf;
		$sql = 'SELECT e.rowid FROM '.$this->db->prefix().'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = e.fk_opportunity';
		$sql .= ' WHERE e.rowid = '.((int) $enrolId).' AND e.entity = '.((int) $conf->entity).' AND o.entity = '.((int) $conf->entity);
		if ($sequenceId > 0) {
			$sql .= ' AND e.fk_sequence = '.((int) $sequenceId);
		}
		$sql .= crmOwnerSql($user, 'o');
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * @param string $reason manual|replied|won|lost
	 * @return int 1 if OK, -1 on error
	 */
	public function stopEnrolment(int $enrolId, string $reason): int
	{
		$sql = 'UPDATE '.$this->db->prefix()."crm_sequence_enrol SET status = 'stopped', stop_reason = '".$this->db->escape($reason)."', date_next = NULL";
		$sql .= ' WHERE rowid = '.((int) $enrolId);
		if (!$this->db->query($sql)) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return 1;
	}

	/**
	 * Someone says the contact of an enrolment replied: stop that enrolment as "replied" and move its deal to the reply stage.
	 *
	 * @return int 1 if OK, -1 on error with the reason in ->error
	 */
	public function markReplied(User $user, int $enrolId): int
	{
		$resql = $this->db->query('SELECT fk_opportunity FROM '.$this->db->prefix().'crm_sequence_enrol WHERE rowid = '.((int) $enrolId));
		$enrol = $resql ? $this->db->fetch_object($resql) : null;
		if (!$enrol || !$this->enrolmentVisible($user, $enrolId)) {
			$this->error = 'CrmErrorEnrolmentNotFound';
			return -1;
		}
		if ($this->stopEnrolment($enrolId, 'replied') <= 0) {
			return -1;
		}
		require_once __DIR__.'/opportunity.class.php';
		$deal = new Opportunity($this->db);
		if ($user->hasRight('crm', 'write') && $deal->fetch((int) $enrol->fk_opportunity) > 0) {
			$deal->moveToReplyStage($user);
		}
		return 1;
	}

	/**
	 * The customer answered: stop every running enrolment of the deal whose sequence stops on reply.
	 *
	 * @return int Number of enrolments stopped, -1 on error
	 */
	public function stopOnReply(int $opportunityId): int
	{
		$sql = 'UPDATE '.$this->db->prefix().'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_sequence as s ON s.rowid = e.fk_sequence';
		$sql .= " SET e.status = 'stopped', e.stop_reason = 'replied', e.date_next = NULL";
		$sql .= ' WHERE e.fk_opportunity = '.((int) $opportunityId).' AND e.entity = '.((int) $GLOBALS['conf']->entity)." AND e.status = 'running' AND s.stop_on_reply = 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * From v1.2.0. Stop every running enrolment of a deal because the prospect used the "not interested" link, whatever the
	 * outreach's own "stop on reply" rule says.
	 */
	public function stopOnOptOut(int $opportunityId): int
	{
		$sql = 'UPDATE '.$this->db->prefix().'crm_sequence_enrol';
		$sql .= " SET status = 'stopped', stop_reason = 'optout', date_next = NULL";
		$sql .= ' WHERE fk_opportunity = '.((int) $opportunityId)." AND status = 'running'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = crmDbError($this->db);
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * @return array<int,object>
	 */
	public function fetchEnrolments(User $user): array
	{
		global $conf;
		$sql = 'SELECT e.*, o.ref, o.label as deal_label, o.status as deal_status';
		$sql .= ' FROM '.$this->db->prefix().'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = e.fk_opportunity';
		$sql .= ' WHERE e.fk_sequence = '.((int) $this->id).' AND o.entity = '.((int) $conf->entity).crmOwnerSql($user, 'o');
		$sql .= ' ORDER BY e.date_enrol DESC';
		return $this->rows($sql);
	}

	/**
	 * SELECT of a journal line with what says why it exists: the enrolment date (for the day of the sequence), the deal and
	 * the sequence.
	 */
	private function logSelect(): string
	{
		$prefix = $this->db->prefix();
		$sql = 'SELECT l.*, e.date_enrol, e.fk_opportunity, e.fk_sequence, o.ref as deal_ref, o.label as deal_label, o.fk_user_resp,';
		$sql .= ' s.ref as seq_ref, s.label as seq_label, st.position as current_step_position, st.delay_days as step_delay_days';
		$sql .= ' FROM '.$prefix.'crm_sequence_log as l';
		$sql .= ' INNER JOIN '.$prefix.'crm_sequence_enrol as e ON e.rowid = l.fk_enrol';
		$sql .= ' INNER JOIN '.$prefix.'crm_sequence as s ON s.rowid = e.fk_sequence';
		$sql .= ' INNER JOIN '.$prefix.'crm_opportunity as o ON o.rowid = e.fk_opportunity';
		$sql .= ' LEFT JOIN '.$prefix.'crm_sequence_step as st ON st.rowid = l.fk_step';
		return $sql;
	}

	/**
	 * Journal of this sequence, newest first.
	 *
	 * @return array<int,object>
	 */
	public function fetchLog(User $user): array
	{
		global $conf;
		$sql = $this->logSelect().' WHERE e.fk_sequence = '.((int) $this->id).' AND o.entity = '.((int) $conf->entity).crmOwnerSql($user, 'o').' ORDER BY l.date_send DESC, l.rowid DESC';
		return $this->rows($sql);
	}

	/**
	 * Every journal line of one deal, newest first: the e-mails the sequences made for it, with the sequence and the day.
	 *
	 * @return array<int,object>
	 */
	public function fetchLogForOpportunity(int $opportunityId): array
	{
		$sql = $this->logSelect().' WHERE e.fk_opportunity = '.((int) $opportunityId).' AND o.entity = '.((int) $GLOBALS['conf']->entity).' ORDER BY l.date_send DESC, l.rowid DESC';
		return $this->rows($sql);
	}

	/**
	 * One journal line, or null.
	 *
	 * @return object|null
	 */
	public function fetchLogLine(int $logId)
	{
		$rows = $this->rows($this->logSelect().' WHERE l.rowid = '.((int) $logId).' AND o.entity = '.((int) $GLOBALS['conf']->entity));
		return $rows[0] ?? null;
	}

	/**
	 * The e-mail of a journal line, kept in the database. A line written before the content was kept has none: it is found
	 * again here and saved, so it can be read later. For a sent message the copy recorded on the deal is the real thing
	 * (source "event"); otherwise the text is rebuilt from the step and the deal as they are now (source "rebuilt"), which
	 * the caller must say, since it may differ from what was sent or planned. A line whose step is gone stays without content.
	 *
	 * @return object|null The line with its content, null when it does not exist or the table has no content column yet
	 */
	public function ensureLogContent(int $logId)
	{
		if (!crmLogContentSupported($this->db)) {
			return null;
		}
		$line = $this->fetchLogLine($logId);
		if ($line === null || (string) $line->body !== '' || (string) $line->content_source !== '') {
			return $line;
		}
		$body = '';
		$source = '';
		$format = '';
		$from = '';
		$subject = (string) $line->subject;
		if ((string) $line->status === 'sent') {
			$sql = 'SELECT a.note, a.email_from FROM '.$this->db->prefix().'actioncomm as a';
			$sql .= " WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND a.fk_element = ".((int) $line->fk_opportunity);
			$sql .= " AND a.code = 'AC_EMAIL' AND a.percent >= 100 AND a.email_subject = '".$this->db->escape($subject)."'";
			$sent = (int) $this->db->jdate($line->date_send);
			$sql .= " AND a.datep >= '".$this->db->idate($sent - 600)."' AND a.datep <= '".$this->db->idate($sent + 600)."'";
			$sql .= ' ORDER BY a.id DESC'.$this->db->plimit(1, 0);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql)) && trim((string) $obj->note) !== '') {
				$body = (string) $obj->note;
				$format = crmLooksLikeHtml($body) ? 'html' : 'text';
				$from = (string) $obj->email_from;
				$source = 'event';
			}
		}
		if ($source === '' && (int) $line->fk_step > 0) {
			$step = $this->stepById((int) $line->fk_step);
			$enrol = $this->enrolForBuild((int) $line->fk_enrol);
			if ($step !== null && $enrol !== null) {
				$built = $this->buildEmail($enrol, $step);
				$body = $built['body'];
				$subject = $built['subject'];
				$format = $built['html'] ? 'html' : 'text';
				$source = 'rebuilt';
			}
		}
		if ($source === '') {
			return $line;
		}
		$sql = 'UPDATE '.$this->db->prefix()."crm_sequence_log SET body = '".$this->db->escape($body)."', body_format = '".$this->db->escape($format)."'";
		$sql .= ", content_source = '".$this->db->escape($source)."'";
		if ($from !== '') {
			$sql .= ", from_email = '".$this->db->escape($from)."'";
		}
		if ((int) ($line->step_position ?? 0) <= 0 && isset($line->current_step_position)) {
			$sql .= ', step_position = '.((int) $line->current_step_position);
		}
		$sql .= ' WHERE rowid = '.((int) $logId);
		$this->db->query($sql);
		return $this->fetchLogLine($logId);
	}

	/**
	 * @return object|null
	 */
	private function stepById(int $stepId)
	{
		$resql = $this->db->query('SELECT * FROM '.$this->db->prefix().'crm_sequence_step WHERE rowid = '.((int) $stepId));
		return ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj : null;
	}

	/**
	 * An enrolment with the deal, account and contact columns that the text of a step is made from.
	 *
	 * @return object|null
	 */
	private function enrolForBuild(int $enrolId)
	{
		$sql = 'SELECT e.rowid as enrol_id, e.fk_opportunity, o.ref, o.label as deal_label, o.amount, o.fk_soc, o.fk_socpeople, o.fk_user_resp,';
		$sql .= ' soc.nom as soc_name, sp.email as contact_email, sp.firstname, sp.lastname';
		$sql .= ' FROM '.$this->db->prefix().'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = e.fk_opportunity';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'societe as soc ON soc.rowid = o.fk_soc';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'socpeople as sp ON sp.rowid = o.fk_socpeople';
		$sql .= ' WHERE e.rowid = '.((int) $enrolId);
		$resql = $this->db->query($sql);
		return ($resql && ($obj = $this->db->fetch_object($resql))) ? $obj : null;
	}

	/**
	 * The subject and the text of an e-mail step for one enrolment, placeholders replaced. In HTML the values are inserted
	 * as text, so a name with a "<" or a "&" cannot add markup to the message.
	 *
	 * @param object $enrol Enrolment row with the deal, account and contact columns
	 * @param object $step  Step row
	 * @return array{subject:string,body:string,html:bool}
	 */
	private function buildEmail($enrol, $step): array
	{
		$contact = trim((string) $enrol->firstname.' '.(string) $enrol->lastname);
		$vars = array(
			'DEAL' => (string) $enrol->deal_label,
			'REF' => (string) $enrol->ref,
			'AMOUNT' => (string) $enrol->amount,
			'CONTACT' => $contact,
			'CUSTOMER' => (string) $enrol->soc_name,
		);
		$isHtml = crmLooksLikeHtml((string) $step->body);
		$bodyVars = $vars;
		if ($isHtml) {
			foreach ($bodyVars as $key => $value) {
				$bodyVars[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
			}
		}
		return array(
			'subject' => crmSingleLine(crmReplacePlaceholders((string) $step->subject, $vars), 255),
			'body' => crmReplacePlaceholders((string) $step->body, $bodyVars),
			'html' => $isHtml,
		);
	}

	/**
	 * Run due steps. At most one step per enrolment per call. E-mails sent or simulated count toward the cap;
	 * drafts do not, since nothing leaves the server.
	 *
	 * @param User $user Cron or MCP user, used as the author of created events
	 * @param int  $max  Maximum e-mails, simulated ones included
	 * @return array{emails:int,drafts:int,steps:int,errors:int}
	 */
	public function runDue(User $user, int $max): array
	{
		global $conf;
		$stats = array('emails' => 0, 'drafts' => 0, 'steps' => 0, 'errors' => 0);
		if (!getDolGlobalInt('CRM_SEQUENCES_ON')) {
			return $stats;
		}
		$dry = getDolGlobalInt('CRM_DRY_RUN');
		$now = dol_now();
		$sql = 'SELECT e.rowid as enrol_id, e.fk_sequence, e.fk_opportunity, e.date_enrol, e.current_step, e.date_next,';
		$sql .= ' s.stop_on_won, s.stop_on_lost, s.active as seq_active,';
		$sql .= ' o.status as deal_status, o.ref, o.label as deal_label, o.amount, o.fk_soc, o.fk_socpeople, o.fk_user_resp,';
		$sql .= ' soc.nom as soc_name, sp.email as contact_email, sp.firstname, sp.lastname';
		$sql .= ' FROM '.$this->db->prefix().'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_sequence as s ON s.rowid = e.fk_sequence';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = e.fk_opportunity';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'societe as soc ON soc.rowid = o.fk_soc';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'socpeople as sp ON sp.rowid = o.fk_socpeople';
		$sql .= " WHERE e.entity = ".((int) $conf->entity)." AND e.status = 'running' AND s.active = 1";
		$sql .= " AND e.date_next IS NOT NULL AND e.date_next <= '".$this->db->idate($now)."'";
		$sql .= ' ORDER BY e.date_next ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = crmDbError($this->db);
			$stats['errors']++;
			return $stats;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			if ((int) $obj->stop_on_won === 1 && (string) $obj->deal_status === 'won') {
				$this->stopEnrolment((int) $obj->enrol_id, 'won');
				continue;
			}
			if ((int) $obj->stop_on_lost === 1 && (string) $obj->deal_status === 'lost') {
				$this->stopEnrolment((int) $obj->enrol_id, 'lost');
				continue;
			}
			$step = $this->stepAt((int) $obj->fk_sequence, (int) $obj->current_step);
			if (!$step) {
				$this->db->query('UPDATE '.$this->db->prefix()."crm_sequence_enrol SET status = 'done', stop_reason = 'done', date_next = NULL WHERE rowid = ".((int) $obj->enrol_id));
				continue;
			}
			if ((string) $step->action === 'email') {
				// Each e-mail step is a draft or a send by its own setting, else by the global one. Only sends count toward the
				// cap; a send step over the cap waits for the next run while the other enrolments go on.
				$draftStep = crmStepDraftMode((string) ($step->send_mode ?? ''));
				if (!$draftStep && $stats['emails'] >= $max) {
					continue;
				}
				if (!$this->claim((int) $obj->enrol_id, (string) $obj->date_next, $now)) {
					continue;
				}
				$emailStatus = $this->runEmailStep($user, $obj, $step, $dry, $draftStep);
				if ($emailStatus === 'draft') {
					$stats['drafts']++;
				} else {
					$stats['emails']++;
				}
				if ($emailStatus === 'error') {
					$stats['errors']++;
					$this->postpone((int) $obj->enrol_id);
					continue;
				}
			} else {
				if (!$this->claim((int) $obj->enrol_id, (string) $obj->date_next, $now)) {
					continue;
				}
				$this->runTaskStep($user, $obj, $step);
			}
			$this->advance((int) $obj->enrol_id, (int) $obj->fk_sequence, (int) $obj->current_step, (int) $this->db->jdate($obj->date_enrol));
			$stats['steps']++;
		}
		return $stats;
	}

	/**
	 * Take an enrolment for this run: two runs at the same time (the cron and an MCP call, or two crons) would otherwise both
	 * read it as due and send the same e-mail twice. The claim moves its due date one hour ahead, only if it still has the
	 * date this run read; whoever changes a row first wins and the other skips it. advance() or postpone() set the real next
	 * date afterwards, and if the run dies the enrolment is due again in an hour.
	 *
	 * @param string $readDate date_next as read, in the format of the database
	 * @return bool True when this run owns the enrolment
	 */
	private function claim(int $enrolId, string $readDate, int $now): bool
	{
		$sql = 'UPDATE '.$this->db->prefix()."crm_sequence_enrol SET date_next = '".$this->db->idate($now + 3600)."'";
		$sql .= ' WHERE rowid = '.((int) $enrolId)." AND status = 'running' AND date_next = '".$this->db->escape($readDate)."'";
		$resql = $this->db->query($sql);
		return ($resql && $this->db->affected_rows($resql) === 1);
	}

	/**
	 * @param object $enrol Enrolment row with deal columns
	 * @param object $step  Step row
	 * @param int    $dry   1 to journal without sending
	 * @param bool   $draftStep True when this step is a draft for the salesperson, false when it is sent
	 * @return string sent|simulated|error
	 */
	private function runEmailStep(User $user, $enrol, $step, int $dry, bool $draftStep): string
	{
		global $conf;
		$built = $this->buildEmail($enrol, $step);
		$subject = $built['subject'];
		$body = $built['body'];
		$isHtml = $built['html'];
		// What the message is sent from, or would be: the journal keeps it with the text.
		$fromEmail = crmSender();
		$email = trim((string) $enrol->contact_email);
		$status = 'sent';
		$error = '';
		if ($draftStep) {
			// Nothing leaves the server. The step becomes a planned e-mail on the deal for the salesperson to review
			// and send from email_compose.php, or from a mail client. Dry-run does not apply: it only guards sending.
			$activity = new CrmActivity($this->db);
			$draftId = $activity->create($user, (int) $enrol->fk_opportunity, (int) $enrol->fk_soc, (int) $enrol->fk_socpeople, (int) $enrol->fk_user_resp, 'email', crmSubjectWithRef($subject, (string) $enrol->ref), dol_now(), 0, $body);
			if ($draftId <= 0) {
				$status = 'error';
				$error = $activity->error;
			} else {
				$status = 'draft';
			}
		} elseif ($email === '') {
			$status = 'error';
			$error = 'No e-mail address on the contact';
		} elseif ($dry) {
			$status = 'simulated';
		} else {
			$result = crmSendMail($email, $subject, $body, (int) $enrol->fk_opportunity, $isHtml);
			if (!$result['ok']) {
				$status = 'error';
				$error = $result['error'];
			} else {
				$fromEmail = (string) $result['from'];
				// The full message goes on the deal. Its Message-ID lets the collector skip the archive-mailbox copy.
				$activity = new CrmActivity($this->db);
				$activity->createEmail($user, (int) $enrol->fk_opportunity, (int) $enrol->fk_soc, (int) $enrol->fk_socpeople, (int) $enrol->fk_user_resp, 'out', $subject, $body, dol_now(), array(
					'msgid' => $result['msgid'],
					'from' => $result['from'],
					'to' => $email,
				));
			}
		}
		// The text is kept as it was made, with the placeholders replaced, whatever the outcome (sent, simulated, draft or
		// error), so the journal can show it later. An installation that has not run the schema step yet keeps the old columns.
		$columns = 'entity, fk_enrol, fk_step, date_send, email, subject, status, error_msg';
		$values = ((int) $conf->entity).', '.((int) $enrol->enrol_id).', '.((int) $step->rowid).", '".$this->db->idate(dol_now())."'";
		$values .= ", '".$this->db->escape($email)."', '".$this->db->escape($subject)."', '".$this->db->escape($status)."', '".$this->db->escape($error)."'";
		if (crmLogContentSupported($this->db)) {
			$columns .= ', from_email, body, body_format, step_position, content_source';
			$values .= ", '".$this->db->escape($fromEmail)."', '".$this->db->escape($body)."', '".($isHtml ? 'html' : 'text')."', ".((int) $step->position).", 'recorded'";
		}
		$this->db->query('INSERT INTO '.$this->db->prefix().'crm_sequence_log ('.$columns.') VALUES ('.$values.')');
		return $status;
	}

	/**
	 * Keep the current step and try again at the start of tomorrow.
	 */
	private function postpone(int $enrolId): void
	{
		$next = crmTodayStart() + 86400;
		$sql = 'UPDATE '.$this->db->prefix()."crm_sequence_enrol SET date_next = '".$this->db->idate($next)."'";
		$sql .= " WHERE rowid = ".((int) $enrolId)." AND status = 'running'";
		$this->db->query($sql);
	}

	/**
	 * A call or a task becomes an agenda event for the owner. It does not send mail.
	 *
	 * @param object $enrol Enrolment row
	 * @param object $step  Step row
	 */
	private function runTaskStep(User $user, $enrol, $step): void
	{
		$kind = ((string) $step->action === 'call') ? 'call' : 'task';
		$activity = new CrmActivity($this->db);
		$activity->create(
			$user,
			(int) $enrol->fk_opportunity,
			(int) $enrol->fk_soc,
			(int) $enrol->fk_socpeople,
			(int) $enrol->fk_user_resp,
			$kind,
			(string) $step->subject,
			dol_now(),
			0
		);
	}

	/**
	 * Move to the next step, or mark the enrolment done.
	 * A failed e-mail does not come here: the caller postpones that enrolment instead.
	 */
	private function advance(int $enrolId, int $sequenceId, int $currentPosition, int $enrolTs): void
	{
		$steps = array();
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_sequence_step WHERE fk_sequence = '.((int) $sequenceId).' ORDER BY position ASC';
		foreach ($this->rows($sql) as $step) {
			$steps[] = $step;
		}
		$index = -1;
		foreach ($steps as $i => $step) {
			if ((int) $step->position === $currentPosition) {
				$index = $i;
				break;
			}
		}
		if ($index < 0 || !isset($steps[$index + 1])) {
			$this->db->query('UPDATE '.$this->db->prefix()."crm_sequence_enrol SET status = 'done', stop_reason = 'done', date_next = NULL WHERE rowid = ".((int) $enrolId));
			return;
		}
		$next = $steps[$index + 1];
		$due = crmDueTimestamp($enrolTs, (int) $next->delay_days);
		$sqlUpdate = 'UPDATE '.$this->db->prefix().'crm_sequence_enrol SET current_step = '.((int) $next->position);
		$sqlUpdate .= ", date_next = '".$this->db->idate($due)."' WHERE rowid = ".((int) $enrolId);
		$this->db->query($sqlUpdate);
	}

	/**
	 * @return object|null
	 */
	private function stepAt(int $sequenceId, int $position)
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'crm_sequence_step WHERE fk_sequence = '.((int) $sequenceId).' AND position = '.((int) $position);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return $obj;
		}
		return null;
	}

	/**
	 * @param string $sql Query
	 * @return array<int,object>
	 */
	private function rows(string $sql): array
	{
		$list = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$list[] = $obj;
			}
		}
		return $list;
	}
}
