<?php
declare(strict_types=1);

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
			$this->error = $this->db->lasterror();
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
		if ($this->ref === '') {
			$this->ref = crmNextSequenceRef($this->db);
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_sequence (entity, ref, label, active, stop_on_reply, stop_on_won, stop_on_lost, datec, fk_user_creat) VALUES (';
		$sql .= ((int) $conf->entity).", '".$this->db->escape($this->ref)."', '".$this->db->escape($this->label)."', ".((int) $this->active);
		$sql .= ', '.((int) $this->stop_on_reply).', '.((int) $this->stop_on_won).', '.((int) $this->stop_on_lost);
		$sql .= ", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
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
		$this->label = trim($this->label);
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
			$this->error = $this->db->lasterror();
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
			$this->error = $this->db->lasterror();
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
			$this->error = $this->db->lasterror();
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
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Enrol a deal. The first step is scheduled from now plus its delay.
	 *
	 * @return int Enrolment id, or -1
	 */
	public function enrol(int $opportunityId): int
	{
		global $conf;
		$steps = $this->fetchSteps();
		if (count($steps) === 0) {
			$this->error = 'CrmErrorSequenceNoSteps';
			return -1;
		}
		$sqlRunning = 'SELECT rowid FROM '.$this->db->prefix()."crm_sequence_enrol WHERE fk_sequence = ".((int) $this->id);
		$sqlRunning .= ' AND fk_opportunity = '.((int) $opportunityId)." AND status = 'running'";
		$resRunning = $this->db->query($sqlRunning);
		if ($resRunning && $this->db->num_rows($resRunning) > 0) {
			$this->error = 'CrmErrorDealEnrolled';
			return -1;
		}
		$first = $steps[0];
		$now = dol_now();
		$next = crmDueTimestamp($now, (int) $first->delay_days);
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_sequence_enrol (entity, fk_sequence, fk_opportunity, date_enrol, current_step, date_next, status) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $this->id).', '.((int) $opportunityId).", '".$this->db->idate($now)."', ".((int) $first->position);
		$sql .= ", '".$this->db->idate($next)."', 'running')";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->last_insert_id($this->db->prefix().'crm_sequence_enrol');
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
			$this->error = $this->db->lasterror();
			return -1;
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
		$sql .= ' WHERE e.fk_opportunity = '.((int) $opportunityId)." AND e.status = 'running' AND s.stop_on_reply = 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * @return array<int,object>
	 */
	public function fetchEnrolments(): array
	{
		$sql = 'SELECT e.*, o.ref, o.label as deal_label, o.status as deal_status';
		$sql .= ' FROM '.$this->db->prefix().'crm_sequence_enrol as e';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_opportunity as o ON o.rowid = e.fk_opportunity';
		$sql .= ' WHERE e.fk_sequence = '.((int) $this->id);
		$sql .= ' ORDER BY e.date_enrol DESC';
		return $this->rows($sql);
	}

	/**
	 * @return array<int,object>
	 */
	public function fetchLog(): array
	{
		$sql = 'SELECT l.* FROM '.$this->db->prefix().'crm_sequence_log as l';
		$sql .= ' INNER JOIN '.$this->db->prefix().'crm_sequence_enrol as e ON e.rowid = l.fk_enrol';
		$sql .= ' WHERE e.fk_sequence = '.((int) $this->id);
		$sql .= ' ORDER BY l.date_send DESC';
		return $this->rows($sql);
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
			$this->error = $this->db->lasterror();
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
				$this->runTaskStep($user, $obj, $step);
			}
			$this->advance((int) $obj->enrol_id, (int) $obj->fk_sequence, (int) $obj->current_step, (int) $this->db->jdate($obj->date_enrol));
			$stats['steps']++;
		}
		return $stats;
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
		$contact = trim((string) $enrol->firstname.' '.(string) $enrol->lastname);
		$vars = array(
			'DEAL' => (string) $enrol->deal_label,
			'REF' => (string) $enrol->ref,
			'AMOUNT' => (string) $enrol->amount,
			'CONTACT' => $contact,
			'CUSTOMER' => (string) $enrol->soc_name,
		);
		$subject = crmReplacePlaceholders((string) $step->subject, $vars);
		// The text of a step is plain or HTML. In HTML the values are inserted as text, so a name with a "<" or a "&"
		// cannot add markup to the message.
		$isHtml = crmLooksLikeHtml((string) $step->body);
		$bodyVars = $vars;
		if ($isHtml) {
			foreach ($bodyVars as $key => $value) {
				$bodyVars[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
			}
		}
		$body = crmReplacePlaceholders((string) $step->body, $bodyVars);
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
				// The full message goes on the deal. Its Message-ID lets the collector skip the archive-mailbox copy.
				$activity = new CrmActivity($this->db);
				$activity->createEmail($user, (int) $enrol->fk_opportunity, (int) $enrol->fk_soc, (int) $enrol->fk_socpeople, (int) $enrol->fk_user_resp, 'out', $subject, $body, dol_now(), array(
					'msgid' => $result['msgid'],
					'from' => $result['from'],
					'to' => $email,
				));
			}
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'crm_sequence_log (entity, fk_enrol, fk_step, date_send, email, subject, status, error_msg) VALUES (';
		$sql .= ((int) $conf->entity).', '.((int) $enrol->enrol_id).', '.((int) $step->rowid).", '".$this->db->idate(dol_now())."'";
		$sql .= ", '".$this->db->escape($email)."', '".$this->db->escape($subject)."', '".$this->db->escape($status)."', '".$this->db->escape($error)."')";
		$this->db->query($sql);
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
