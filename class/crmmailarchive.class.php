<?php
declare(strict_types=1);

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
 *    who answers a customer answer from their own mail client and quotes the exchange.
 * Anything else is ignored: the mailbox is not a general inbox.
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
	 */
	public function findDeal(array $messageIds): int
	{
		global $conf;
		foreach ($messageIds as $messageId) {
			$dealId = crmDealIdFromMessageId($messageId);
			if ($dealId > 0 && $this->dealExists($dealId)) {
				return $dealId;
			}
		}
		if (count($messageIds) === 0) {
			return 0;
		}
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
	 * @param array<string,mixed>  $mail msgid, in_reply_to, references, from, to, cc, subject, body, date (timestamp)
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
		$dealId = $this->findDeal($ids);
		if ($dealId <= 0) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}
		$deal = $this->fetchDeal($dealId);
		if (!$deal) {
			return array('status' => 'ignored', 'deal' => 0, 'event' => 0);
		}

		$fromAddresses = crmAddressesIn((string) ($mail['from'] ?? ''));
		$fromAddress = count($fromAddresses) > 0 ? $fromAddresses[0] : '';
		$direction = $this->isInternalAddress($fromAddress) ? 'out' : 'in';
		$date = (int) ($mail['date'] ?? 0);
		if ($date <= 0) {
			$date = dol_now();
		}
		$body = (string) ($mail['body'] ?? '');
		if (dol_strlen($body) > 100000) {
			$body = dol_substr($body, 0, 100000);
		}
		$subject = trim((string) ($mail['subject'] ?? ''));
		if ($subject === '') {
			$subject = '(no subject)';
		}

		$activity = new CrmActivity($this->db);
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
				'msgid' => $msgid,
				'from' => (string) ($mail['from'] ?? ''),
				'to' => (string) ($mail['to'] ?? ''),
				'cc' => (string) ($mail['cc'] ?? ''),
			)
		);
		if ($eventId === 0) {
			return array('status' => 'duplicate', 'deal' => $dealId, 'event' => 0);
		}
		if ($eventId < 0) {
			$this->error = $activity->error;
			return array('status' => 'error', 'deal' => $dealId, 'event' => 0);
		}
		if ($direction === 'in') {
			(new Sequence($this->db))->stopOnReply($dealId);
		}
		return array('status' => 'recorded', 'deal' => $dealId, 'event' => $eventId);
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
