<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

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
			$this->error = 'CrmErrorEventNotStep';
			return -1;
		}
		if (!CrmActivity::isPlannedEmail((string) $event->code, (int) $event->percentage)) {
			$this->error = 'CrmErrorNotPlannedEmail';
			return -1;
		}
		$sql = 'SELECT o.rowid, o.ref, o.fk_soc, o.fk_socpeople, o.fk_user_resp, sp.email as contact_email';
		$sql .= ' FROM '.$this->db->prefix().'crm_opportunity as o';
		$sql .= ' LEFT JOIN '.$this->db->prefix().'socpeople as sp ON sp.rowid = o.fk_socpeople';
		$sql .= ' WHERE o.rowid = '.((int) $event->fk_element).' AND o.entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		$deal = $resql ? $this->db->fetch_object($resql) : null;
		if (!$deal) {
			$this->error = 'CrmErrorDealNotFound';
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
		// The title as it is: nothing adds a marker of the deal to it (the headers of what is sent carry the deal).
		return (string) $this->event->label;
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
	 * @param bool   $html True when the text is HTML: it is sent with a plain-text alternative
	 * @param string $from Sender address chosen among crmSenderChoices(), empty for the sender of the module
	 * @param string $copyToMe Address of the user who sends, for a hidden copy to themselves; empty for none
	 * @return int 1 if OK, -1 on error. When the mail left but could not be recorded, error says so.
	 */
	public function send(User $user, string $to, string $subject, string $body, bool $html = false, string $from = '', string $copyToMe = ''): int
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
		require_once __DIR__.'/crmmailfile.class.php';
		$attachments = (new CrmMailFile($this->db))->forSend((int) $this->event->id);
		$result = crmSendMail($to, $subject, $body, (int) $this->deal->rowid, $html, $from, $copyToMe, $attachments);
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
	 * @param string $from Address the person sent it from, empty for the sender of the module
	 * @return int 1 if OK, -1 on error
	 */
	public function markSentManually(User $user, string $to, string $subject, string $body, string $from = ''): int
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
			'from' => ($from !== '' && isValidEmail($from)) ? $from : crmSender(),
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
		$eventId = (int) $this->event->id;
		if ($this->event->delete($user) < 0) {
			$this->error = (string) $this->event->error;
			return -1;
		}
		// What is still attached to this event (a draft discarded, not one replaced by its sent message) goes with it.
		require_once __DIR__.'/crmmailfile.class.php';
		(new CrmMailFile($this->db))->deleteForAction($eventId);
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
		// The attached files follow the message, which has a new event: they stay on disk for the message history.
		require_once __DIR__.'/crmmailfile.class.php';
		(new CrmMailFile($this->db))->repoint((int) $this->event->id, (int) $id);
		return $this->discard($user);
	}
}
