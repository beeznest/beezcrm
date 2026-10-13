<?php
declare(strict_types=1);

/**
 * \file    class/crmemaildraft.class.php
 * \ingroup crm
 * \brief   A planned e-mail step on a deal: review the suggested text, then send it, or register it as sent by hand.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';

/**
 * The draft is the planned agenda event itself: the label is the subject and the note is the text. Nothing is
 * stored elsewhere. Sending, registering a manual send and discarding replace it with a done e-mail event,
 * or remove it, so the history never shows the same message twice.
 */
class CrmEmailDraft
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var ActionComm|null */
	public $event;
	/** @var object|null Deal row: rowid, ref, fk_soc, fk_socpeople, fk_user_resp, contact_email */
	public $deal;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}

	/**
	 * Load a planned e-mail event of a deal of the current entity.
	 *
	 * @return int 1 if OK, -1 if the event is not a planned e-mail step of a deal
	 */
	public function fetch(int $eventId): int
	{
		global $conf;
		$event = new ActionComm($this->db);
		if ($eventId <= 0 || $event->fetch($eventId) <= 0 || (string) $event->elementtype !== CrmActivity::ELEMENT_TYPE) {
			$this->error = 'Not a CRM step';
			return -1;
		}
		if (!CrmActivity::isPlannedEmail((string) $event->code, (int) $event->percentage)) {
			$this->error = 'Not a planned e-mail';
			return -1;
		}
		$sql = 'SELECT o.rowid, o.ref, o.fk_soc, o.fk_socpeople, o.fk_user_resp, sp.email as contact_email';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'socpeople as sp ON sp.rowid = o.fk_socpeople';
		$sql .= ' WHERE o.rowid = '.((int) $event->fk_element).' AND o.entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		$deal = $resql ? $this->db->fetch_object($resql) : null;
		if (!$deal) {
			$this->error = 'Deal not found';
			return -1;
		}
		$this->event = $event;
		$this->deal = $deal;
		return 1;
	}

	/**
	 * Address of the deal contact, the default recipient.
	 */
	public function contactEmail(): string
	{
		return $this->deal ? trim((string) $this->deal->contact_email) : '';
	}

	/**
	 * Subject kept on the draft, with the deal reference added so the message can be tied back to the deal.
	 */
	public function subject(): string
	{
		if (!$this->event || !$this->deal) {
			return '';
		}
		return crmSubjectWithRef((string) $this->event->label, (string) $this->deal->ref);
	}

	/**
	 * Keep an edited subject and text on the draft.
	 *
	 * @return int 1 if OK, -1 on error
	 */
	public function save(User $user, string $subject, string $body): int
	{
		if (!$this->event || !$this->deal) {
			return -1;
		}
		$subject = trim($subject);
		if ($subject === '') {
			$this->error = 'CrmErrorSubjectRequired';
			return -1;
		}
		$this->event->label = $subject;
		$this->event->note_private = $body;
		if ($this->event->update($user) < 0) {
			$this->error = (string) $this->event->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Send the message through Dolibarr, record it on the deal, remove the draft.
	 *
	 * @return int 1 if OK, -1 on error. When the mail left but could not be recorded, error says so.
	 */
	public function send(User $user, string $to, string $subject, string $body): int
	{
		if (!$this->event || !$this->deal) {
			return -1;
		}
		$to = trim($to);
		$subject = trim($subject);
		if ($to === '' || !isValidEmail($to)) {
			$this->error = 'CrmErrorRecipientInvalid';
			return -1;
		}
		if ($subject === '') {
			$this->error = 'CrmErrorSubjectRequired';
			return -1;
		}
		$result = crmSendMail($to, $subject, $body, (int) $this->deal->rowid);
		if (!$result['ok']) {
			$this->error = $result['error'] !== '' ? $result['error'] : 'CrmErrorSendFailed';
			return -1;
		}
		return $this->replaceWithDone($user, 'out', $subject, $body, array(
			'msgid' => $result['msgid'],
			'from' => $result['from'],
			'to' => $to,
		), 'CrmErrorSentNotRecorded');
	}

	/**
	 * The person sent the message from a mail client. Record it as done now, without a Message-ID: when the
	 * collector reads the copy, CrmMailArchive replaces this event with the real message.
	 *
	 * @return int 1 if OK, -1 on error
	 */
	public function markSentManually(User $user, string $to, string $subject, string $body): int
	{
		if (!$this->event || !$this->deal) {
			return -1;
		}
		$subject = trim($subject);
		if ($subject === '') {
			$this->error = 'CrmErrorSubjectRequired';
			return -1;
		}
		return $this->replaceWithDone($user, 'out', $subject, $body, array(
			'msgid' => '',
			'from' => crmSender(),
			'to' => trim($to),
		), 'CrmErrorSentNotRecorded');
	}

	/**
	 * Remove the draft without recording anything.
	 *
	 * @return int 1 if OK, -1 on error
	 */
	public function discard(User $user): int
	{
		if (!$this->event) {
			return -1;
		}
		if ($this->event->delete($user) < 0) {
			$this->error = (string) $this->event->error;
			return -1;
		}
		return 1;
	}

	/**
	 * @param array<string,string> $meta msgid, from, to
	 */
	private function replaceWithDone(User $user, string $direction, string $subject, string $body, array $meta, string $failure): int
	{
		$activity = new CrmActivity($this->db);
		$id = $activity->createEmail(
			$user,
			(int) $this->deal->rowid,
			(int) $this->deal->fk_soc,
			(int) $this->deal->fk_socpeople,
			(int) $this->deal->fk_user_resp,
			$direction,
			$subject,
			$body,
			dol_now(),
			$meta
		);
		if ($id < 0) {
			$this->error = $failure;
			return -1;
		}
		return $this->discard($user);
	}
}
