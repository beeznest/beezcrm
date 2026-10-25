<?php
declare(strict_types=1);

if (!defined('DOL_DOCUMENT_ROOT')) {
	exit;
}

/**
 * \file    class/crmmailarchive.class.php
 * \ingroup crm
 * \brief   Attach e-mails read from the dedicated mailbox to the deal they belong to.
 */

require_once __DIR__.'/../lib/crm.lib.php';
require_once __DIR__.'/crmactivity.class.php';
require_once __DIR__.'/sequence.class.php';

/**
 * Called by the Dolibarr e-mail collector (see ActionsCrm::doCollectImapOneCollector). The module does not
 * read a mailbox itself.
 *
 * A message is kept only when it can be tied to a deal:
 * 1. a Message-ID, In-Reply-To or References entry carries the deal tracking id the module puts on what it sends;
 * 2. or one of those ids is the Message-ID of a message already recorded on a deal, which covers a person
 *    who answers a customer answer from their own mail client and quotes the exchange;
 * 3. or the subject carries the marker of a deal in square brackets, "[O2026-0001]" ("[OPP2026-0001]" before 1.2.0). The module puts it in the
 *    subject of the e-mails it suggests, so a message written in a mail client from such a suggestion, and the
 *    customer's answer to it, are recognised even when the mail client drops the threading headers.
 * Anything else is ignored: the mailbox is not a general inbox.
 *
 * Finding the deal is not enough, since a reference or a Message-ID can be guessed and a From header can be forged. A
 * message is kept only when it also comes from, or (for a message sent by us) goes to, an address the deal knows: its
 * contact, the other contacts of its third party, the address of the third party, or a colleague at one of their domains
 * (public mail domains do not count). A message the mail server reports as failing SPF/DKIM/DMARC is ignored, and with
 * the setting CRM_ARCHIVE_REQUIRE_AUTH one that does not pass is ignored too. A message written by a machine (bounce,
 * out-of-office, list) is kept but does not stop the outreach or move the deal.
 */
class CrmMailArchive
{
	public const COLLECTOR_OPERATION = 'hookcrmrecordemail';

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
	 * Deal this thread belongs to, or 0.
	 *
	 * @param string[] $messageIds Message-ID, In-Reply-To and References entries, without angle brackets
	 * @param string   $subject    Subject, searched for a deal reference in square brackets when the ids give nothing
	 */
	public function findDeal(array $messageIds, string $subject = ''): int
	{
		global $conf;
		foreach ($messageIds as $messageId) {
			$dealId = crmDealIdFromMessageId($messageId);
			if ($dealId > 0 && $this->dealExists($dealId)) {
				return $dealId;
			}
		}
		if (count($messageIds) > 0) {
			$dealId = $this->findDealByThread($messageIds);
			if ($dealId > 0) {
				return $dealId;
			}
		}
		return $this->findDealBySubject($subject);
	}

	/**
	 * @param string[] $messageIds Message-IDs to look for among the messages already recorded on a deal
	 */
	private function findDealByThread(array $messageIds): int
	{
		global $conf;
		$quoted = array();
		foreach ($messageIds as $messageId) {
			$quoted[] = "'".$this->db->escape($messageId)."'";
		}
		$sql = 'SELECT a.fk_element FROM '.$this->db->prefix().'actioncomm as a';
		$sql .= " INNER JOIN ".$this->db->prefix().'crm_opportunity as o ON o.rowid = a.fk_element';
		$sql .= " WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."'";
		$sql .= ' AND o.entity = '.((int) $conf->entity);
		$sql .= ' AND a.email_msgid IN ('.implode(',', $quoted).')';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->fk_element;
		}
		return 0;
	}

	/**
	 * Deal whose reference appears in square brackets in the subject.
	 */
	private function findDealBySubject(string $subject): int
	{
		global $conf;
		$quoted = array();
		foreach (crmExpandDealMarkers(crmDealRefsInSubject($subject)) as $ref) {
			$quoted[] = "'".$this->db->escape($ref)."'";
		}
		if (count($quoted) === 0) {
			return 0;
		}
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'crm_opportunity';
		$sql .= ' WHERE entity = '.((int) $conf->entity).' AND ref IN ('.implode(',', $quoted).')';
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * From v1.2.0. Open deal whose contact has one of these addresses, when there is exactly one such deal.
	 * Several open deals for the same contact is ambiguous: nothing is attached rather than the wrong deal.
	 *
	 * @param string[] $addresses The other end of the message: the sender of a received one, the recipients of a sent one
	 */
	public function findDealByContact(array $addresses): int
	{
		global $conf;
		$quoted = array();
		foreach ($addresses as $address) {
			$address = strtolower(trim((string) $address));
			if ($address !== '' && !$this->isInternalAddress($address)) {
				$quoted[$address] = "'".$this->db->escape($address)."'";
			}
		}
		if (count($quoted) === 0) {
			return 0;
		}
		$prefix = $this->db->prefix();
		$sql = 'SELECT DISTINCT o.rowid FROM '.$prefix.'crm_opportunity as o';
		$sql .= ' INNER JOIN '.$prefix.'socpeople as c ON c.rowid = o.fk_socpeople';
		$sql .= ' WHERE o.entity = '.((int) $conf->entity)." AND o.status = 'open'";
		$sql .= ' AND LOWER(TRIM(c.email)) IN ('.implode(',', $quoted).')';
		$sql .= $this->db->plimit(2, 0);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * The addresses the deal knows: its contact, the other contacts of its third party, and the third party itself.
	 *
	 * @param object $deal Deal row with fk_soc and fk_socpeople
	 * @return string[]
	 */
	private function knownAddresses($deal): array
	{
		global $conf;
		$prefix = $this->db->prefix();
		$known = array();
		$socId = (int) $deal->fk_soc;
		if ($socId > 0) {
			$resql = $this->db->query('SELECT email FROM '.$prefix.'societe WHERE rowid = '.$socId.' AND entity IN ('.getEntity('societe').')');
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$known = array_merge($known, crmAddressesIn((string) $obj->email));
			}
			$resql = $this->db->query('SELECT email FROM '.$prefix.'socpeople WHERE fk_soc = '.$socId.' AND entity IN ('.getEntity('societe').") AND email IS NOT NULL AND email <> ''");
			while ($resql && ($obj = $this->db->fetch_object($resql))) {
				$known = array_merge($known, crmAddressesIn((string) $obj->email));
			}
		}
		if ((int) $deal->fk_socpeople > 0) {
			$resql = $this->db->query('SELECT email FROM '.$prefix.'socpeople WHERE rowid = '.((int) $deal->fk_socpeople).' AND entity IN ('.getEntity('societe').')');
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$known = array_merge($known, crmAddressesIn((string) $obj->email));
			}
		}
		return array_values(array_unique($known));
	}

	/**
	 * True when the address is the module sender or belongs to a Dolibarr user, so the message was sent by us.
	 */
	public function isInternalAddress(string $address): bool
	{
		$address = strtolower(trim($address));
		if ($address === '') {
			return false;
		}
		$own = array_merge(
			crmAddressesIn(crmSender()),
			crmAddressesIn(getDolGlobalString('MAIL_FROM_EMAILS_TO_CONSIDER_SENDING'))
		);
		if (in_array($address, $own, true)) {
			return true;
		}
		$sql = 'SELECT rowid FROM '.$this->db->prefix()."user WHERE LOWER(email) = '".$this->db->escape($address)."'";
		$sql .= $this->db->plimit(1, 0);
		$resql = $this->db->query($sql);
		return ($resql && $this->db->num_rows($resql) > 0);
	}

	/**
	 * Record one message on its deal.
	 *
	 * @param User                 $user User saved as author of the event
	 * @param array<string,mixed>  $mail msgid, in_reply_to, references, from, to, cc, subject, body, date (timestamp), auto (bool: written
	 *                                   by a machine), authresults (Authentication-Results header of our own server, or '')
	 * @return array{status:string,deal:int,event:int} status is recorded, duplicate, ignored or error
	 */
	public function record(User $user, array $mail): array
	{
		$msgid = trim((string) ($mail['msgid'] ?? ''), '<> ');
		$ids = array();
		foreach (array((string) ($mail['in_reply_to'] ?? ''), (string) ($mail['references'] ?? ''), '<'.$msgid.'>') as $value) {
			foreach (crmMessageIds($value) as $id) {
				if (!in_array($id, $ids, true)) {
					$ids[] = $id;
				}
			}
		}
		$fromAddresses = crmAddressesIn((string) ($mail['from'] ?? ''));
		$fromAddress = count($fromAddresses) > 0 ? $fromAddresses[0] : '';
		$direction = $this->isInternalAddress($fromAddress) ? 'out' : 'in';
		$dealId = $this->findDeal($ids, (string) ($mail['subject'] ?? ''));
		if ($dealId <= 0) {
			// From v1.2.0: no tracking id, thread or reference, but the other end is the contact of exactly one open deal.
			$counterparts = $direction === 'in'
				? array($fromAddress)
				: array_merge(crmAddressesIn((string) ($mail['to'] ?? '')), crmAddressesIn((string) ($mail['cc'] ?? '')));
			$dealId = $this->findDealByContact($counterparts);
		}
		if ($dealId <= 0) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}
		$deal = $this->fetchDeal($dealId);
		if (!$deal) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}

		// Who wrote it, who it goes to, and does the deal know any of them? Otherwise it is not ours to attach.
		$authResults = trim((string) ($mail['authresults'] ?? ''));
		if ($authResults !== '' && !crmMailAuthPassed($authResults)) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}
		if (getDolGlobalInt('CRM_ARCHIVE_REQUIRE_AUTH') && !crmMailAuthPassed($authResults)) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}
		$known = $this->knownAddresses($deal);
		$trusted = false;
		if ($direction === 'in') {
			$trusted = crmSenderTrusted($fromAddress, $known);
		} else {
			foreach (array_merge(crmAddressesIn((string) ($mail['to'] ?? '')), crmAddressesIn((string) ($mail['cc'] ?? ''))) as $recipient) {
				if (crmSenderTrusted($recipient, $known)) {
					$trusted = true;
					break;
				}
			}
		}
		if (!$trusted) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}
		$automatic = !empty($mail['auto']);
		$date = crmClampMailDate((int) ($mail['date'] ?? 0), dol_now());
		$body = (string) ($mail['body'] ?? '');
		if (dol_strlen($body) > 100000) {
			$body = dol_substr($body, 0, 100000);
		}
		$subject = crmSingleLine((string) ($mail['subject'] ?? ''), 250);
		if ($subject === '') {
			$subject = '(no subject)';
		}

		$activity = new CrmActivity($this->db);
		if ($msgid !== '') {
			$existing = new ActionComm($this->db);
			if ($existing->fetch(0, '', '', $msgid) > 0) {
				return array('status' => 'duplicate', 'deal' => $dealId, 'event' => 0);
			}
		}
		if ($direction === 'out') {
			// A message we said we were sending, or sent by hand, is replaced by the real one rather than doubled.
			$pending = $this->findPendingOutgoing($dealId, $subject);
			if ($pending) {
				$pending->delete($user);
			}
		}
		$eventId = $activity->createEmail(
			$user,
			$dealId,
			(int) $deal->fk_soc,
			(int) $deal->fk_socpeople,
			(int) $deal->fk_user_resp,
			$direction,
			$subject,
			$body,
			$date,
			array(
				'msgid' => crmSingleLine($msgid, 250),
				'from' => crmSingleLine((string) ($mail['from'] ?? ''), 250),
				'to' => crmSingleLine((string) ($mail['to'] ?? ''), 250),
				'cc' => crmSingleLine((string) ($mail['cc'] ?? ''), 250),
			)
		);
		if ($eventId === 0) {
			return array('status' => 'duplicate', 'deal' => $dealId, 'event' => 0);
		}
		if ($eventId < 0) {
			$this->error = $activity->error;
			return array('status' => 'error', 'deal' => $dealId, 'event' => 0);
		}
		if ($direction === 'in' && !$automatic) {
			(new Sequence($this->db))->stopOnReply($dealId);
			// The prospect answered: the deal goes to the reply stage of its pipeline, to be qualified.
			require_once __DIR__.'/opportunity.class.php';
			$dealObject = new Opportunity($this->db);
			if ($dealObject->fetch($dealId) > 0) {
				$dealObject->moveToReplyStage($user);
			}
		}
		return array('status' => 'recorded', 'deal' => $dealId, 'event' => $eventId);
	}

	/**
	 * The event this outgoing message stands for, when the deal already has one:
	 * an e-mail registered as sent by hand that no real message has replaced yet (oldest first, same subject first),
	 * or a planned e-mail step with the same subject that was sent from a mail client without being registered.
	 *
	 * @return ActionComm|null
	 */
	private function findPendingOutgoing(int $dealId, string $subject)
	{
		$wanted = crmNormalizeSubject($subject);
		$sql = 'SELECT a.id, a.label, a.percent, a.email_subject, a.datep FROM '.$this->db->prefix().'actioncomm as a';
		$sql .= " WHERE a.elementtype = '".$this->db->escape(CrmActivity::ELEMENT_TYPE)."' AND a.fk_element = ".((int) $dealId);
		$sql .= " AND a.code = 'AC_EMAIL' AND ((a.percent >= 100 AND (a.email_msgid IS NULL OR a.email_msgid = ''))";
		$sql .= " OR a.percent < 100)";
		$sql .= " AND a.datep >= '".$this->db->idate(dol_now() - (30 * 86400))."'";
		$sql .= ' ORDER BY a.datep ASC';
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		$firstManual = 0;
		$matchId = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$candidate = ((int) $obj->percent >= 100) ? (string) $obj->email_subject : (string) $obj->label;
			if ($candidate !== '' && crmNormalizeSubject($candidate) === $wanted) {
				$matchId = (int) $obj->id;
				break;
			}
			if ($firstManual === 0 && (int) $obj->percent >= 100) {
				$firstManual = (int) $obj->id;
			}
		}
		$id = $matchId > 0 ? $matchId : $firstManual;
		if ($id <= 0) {
			return null;
		}
		$event = new ActionComm($this->db);
		return ($event->fetch($id) > 0) ? $event : null;
	}

	/**
	 * @return object|null Deal row with fk_soc, fk_socpeople, fk_user_resp
	 */
	private function fetchDeal(int $dealId)
	{
		global $conf;
		$sql = 'SELECT rowid, fk_soc, fk_socpeople, fk_user_resp FROM '.$this->db->prefix().'crm_opportunity';
		$sql .= ' WHERE rowid = '.((int) $dealId).' AND entity = '.((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return $obj;
		}
		return null;
	}

	private function dealExists(int $dealId): bool
	{
		return $this->fetchDeal($dealId) !== null;
	}
}
