<?php
declare(strict_types=1);

/**
 * Pure CRM rules. This file does not load Dolibarr, so the checks in test/ can run alone.
 */

/**
 * Replace known __TOKEN__ placeholders. Unknown tokens stay as written.
 *
 * @param array<string,string> $vars Keys without underscores, e.g. REF
 */
function crmReplacePlaceholders(string $text, array $vars): string
{
	foreach ($vars as $key => $value) {
		$text = str_replace('__'.$key.'__', $value, $text);
	}
	return $text;
}

/**
 * Sum of points for answered qualification items, capped at 100.
 *
 * @param array<int,array{points:int,answer:int}> $items
 */
function crmScore(array $items): int
{
	$sum = 0;
	foreach ($items as $item) {
		if (!empty($item['answer'])) {
			$sum += (int) $item['points'];
		}
	}
	if ($sum < 0) {
		return 0;
	}
	if ($sum > 100) {
		return 100;
	}
	return $sum;
}

/**
 * Absolute timestamp of a sequence step. Delay is counted from enrolment, not from the previous step.
 */
function crmDueTimestamp(int $enrolTs, int $delayDays): int
{
	if ($delayDays < 0) {
		$delayDays = 0;
	}
	return $enrolTs + ($delayDays * 86400);
}

/**
 * A deal rots when the latest done activity, or its creation when nothing was done, is older than the stage delay.
 * A delay of 0 disables rotting for that stage.
 */
function crmIsRotting(int $lastActivityTs, int $createdTs, int $rottingDays, int $now): bool
{
	if ($rottingDays <= 0) {
		return false;
	}
	$base = $lastActivityTs > 0 ? $lastActivityTs : $createdTs;
	if ($base <= 0) {
		return false;
	}
	return ($now - $base) > ($rottingDays * 86400);
}

/**
 * Weighted amount from the stage probability.
 */
function crmWeighted(float $amount, int $probability): float
{
	if ($probability < 0) {
		$probability = 0;
	}
	if ($probability > 100) {
		$probability = 100;
	}
	return $amount * $probability / 100.0;
}

/**
 * Tracking id put in the Message-ID of every message the module sends for a deal. Dolibarr builds the
 * Message-ID as "<unique>.<transport>-dolibarr-<trackid>@<host>", and the e-mail collector parses the
 * same "dolibarr-<letters><digits>@" signature, so a threaded answer carries it in References.
 */
function crmTrackId(int $dealId): string
{
	return 'crm'.$dealId;
}

/**
 * Deal id found in one Message-ID, or 0.
 */
function crmDealIdFromMessageId(string $messageId): int
{
	if (preg_match('/dolibarr-crm([0-9]+)@/', $messageId, $reg)) {
		return (int) $reg[1];
	}
	return 0;
}

/**
 * Value of one header in a raw header block. Folded lines are expected to be merged already.
 */
function crmHeaderValue(string $rawHeaders, string $name): string
{
	if (preg_match('/^'.preg_quote($name, '/').':[ \t]*(.*)$/mi', $rawHeaders, $reg)) {
		return trim($reg[1]);
	}
	return '';
}

/**
 * Message-IDs (without angle brackets) in a References or In-Reply-To value, in order, without duplicates.
 *
 * @return string[]
 */
function crmMessageIds(string $value): array
{
	$ids = array();
	if (preg_match_all('/<([^<>\s]+)>/', $value, $matches)) {
		foreach ($matches[1] as $id) {
			if (!in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}
	}
	return $ids;
}

/**
 * Bare addresses in an address list such as 'Ada <ada@example.com>, bob@example.com', lowercased.
 *
 * @return string[]
 */
function crmAddressesIn(string $list): array
{
	$addresses = array();
	if (preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $list, $matches)) {
		foreach ($matches[0] as $address) {
			$address = strtolower($address);
			if (!in_array($address, $addresses, true)) {
				$addresses[] = $address;
			}
		}
	}
	return $addresses;
}

/**
 * Deal references written between square brackets in a subject, in order: "Re: Offer [OPP2026-0001]".
 * The module puts one in the subject of the e-mails it suggests, so a message written in a mail client and
 * the customer's answer to it can be tied back to the deal. Candidates only: the caller checks they exist.
 *
 * @return string[]
 */
function crmDealRefsInSubject(string $subject): array
{
	$refs = array();
	if (preg_match_all('/\[([A-Za-z0-9][A-Za-z0-9_\-]{2,31})\]/', $subject, $matches)) {
		foreach ($matches[1] as $ref) {
			if (!in_array($ref, $refs, true)) {
				$refs[] = $ref;
			}
		}
	}
	return $refs;
}

/**
 * Subject reduced for comparison: reply and forward prefixes removed, case and spaces ignored.
 */
function crmNormalizeSubject(string $subject): string
{
	$subject = trim($subject);
	while (preg_match('/^(re|fw|fwd|tr|aw|wg)\s*:\s*/i', $subject, $reg)) {
		$subject = substr($subject, strlen($reg[0]));
	}
	return strtolower(trim((string) preg_replace('/\s+/', ' ', $subject)));
}

/**
 * Subject with the deal reference appended when it is not already in it.
 */
function crmSubjectWithRef(string $subject, string $ref): string
{
	$subject = trim($subject);
	if ($ref === '' || strpos($subject, '['.$ref.']') !== false) {
		return $subject;
	}
	return trim($subject.' ['.$ref.']');
}

/**
 * mailto: link with recipients, subject and body filled. Line breaks are CRLF, as mail clients expect.
 */
function crmMailtoUrl(string $to, string $subject, string $body, string $cc = '', string $bcc = ''): string
{
	$query = array();
	if ($cc !== '') {
		$query[] = 'cc='.rawurlencode($cc);
	}
	if ($bcc !== '') {
		$query[] = 'bcc='.rawurlencode($bcc);
	}
	$query[] = 'subject='.rawurlencode($subject);
	$query[] = 'body='.rawurlencode(str_replace(array("\r\n", "\r", "\n"), "\r\n", $body));
	return 'mailto:'.rawurlencode($to).'?'.implode('&', $query);
}

/**
 * Message text without the quoted lines of earlier messages (lines starting with ">"), blank runs collapsed,
 * cut to a length with an ellipsis. Used to keep what is sent to an AI service short.
 */
function crmCompactMessage(string $text, int $max): string
{
	$kept = array();
	foreach (preg_split('/\r\n|\r|\n/', $text) ?: array() as $line) {
		if (preg_match('/^\s*>/', $line)) {
			continue;
		}
		$kept[] = rtrim($line);
	}
	$text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));
	if ($max > 0 && strlen($text) > $max) {
		$text = rtrim(substr($text, 0, $max))."\n[...]";
	}
	return $text;
}

/**
 * Exchange as plain text for a prompt, oldest first. When it does not fit in the total length, the oldest messages
 * are dropped, never the latest ones, and a line says so.
 *
 * @param array<int,array{date:string,direction:string,subject:string,body:string}> $messages Oldest first; direction is out or in
 * @param string $weLabel       Label of a message we sent
 * @param string $theyLabel     Label of a message the customer sent
 * @param string $omittedLabel  Line used when older messages were left out
 */
function crmThreadText(array $messages, int $perMessage, int $total, string $weLabel, string $theyLabel, string $omittedLabel): string
{
	$blocks = array();
	foreach ($messages as $message) {
		$who = ($message['direction'] === 'out') ? $weLabel : $theyLabel;
		$blocks[] = '--- '.$message['date'].' | '.$who.' | '.trim($message['subject'])."\n".crmCompactMessage($message['body'], $perMessage);
	}
	$kept = array();
	$length = 0;
	for ($i = count($blocks) - 1; $i >= 0; $i--) {
		$length += strlen($blocks[$i]) + 2;
		if ($length > $total && count($kept) > 0) {
			array_unshift($kept, $omittedLabel);
			break;
		}
		array_unshift($kept, $blocks[$i]);
	}
	return implode("\n\n", $kept);
}

/**
 * HTML ready to be sent. Line feeds are added after the end of each block, which changes nothing on screen (HTML
 * ignores them) but lets a mail library that builds the plain-text alternative by stripping the tags, as Dolibarr's
 * does, produce readable paragraphs and lines instead of one run-on line.
 */
function crmHtmlForMail(string $html): string
{
	$html = trim(str_replace(array("\r\n", "\r"), "\n", $html));
	// Whitespace between blocks means nothing in HTML, so it is replaced by exactly the breaks wanted in the text version.
	$html = (string) preg_replace('#(<br\s*/?>)[ \t]*\n?#i', "$1\n", $html);
	$html = (string) preg_replace('#(</(?:p|div|h[1-6]|blockquote|pre|table|ul|ol)>)\s*#i', "$1\n\n", $html);
	$html = (string) preg_replace('#(</(?:li|tr)>)\s*#i', "$1\n", $html);
	return trim($html);
}

/**
 * The plain-text version of an HTML message, built the way the mail library does (tags stripped, entities decoded),
 * from the HTML prepared by crmHtmlForMail. Paragraph breaks are kept, blank runs collapsed. Link addresses are not
 * repeated: an address that matters has to be written in the text.
 */
function crmHtmlToText(string $html): string
{
	$text = html_entity_decode(strip_tags(crmHtmlForMail($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = str_replace("\xc2\xa0", ' ', $text);
	$text = (string) preg_replace("/[ \t]+\n/", "\n", $text);
	return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
}

/**
 * A plain text turned into simple HTML: escaped, blank lines make paragraphs, other line breaks make <br>.
 */
function crmTextToHtml(string $text): string
{
	$text = trim(str_replace(array("\r\n", "\r"), "\n", $text));
	if ($text === '') {
		return '';
	}
	$paragraphs = array();
	foreach (preg_split("/\n{2,}/", $text) ?: array() as $block) {
		$paragraphs[] = '<p>'.str_replace("\n", "<br>\n", htmlspecialchars($block, ENT_QUOTES, 'UTF-8')).'</p>';
	}
	return implode("\n", $paragraphs);
}

/**
 * True when the text is HTML as the editor writes it: it contains a tag of a common block or inline element.
 */
function crmLooksLikeHtml(string $text): bool
{
	return (bool) preg_match('#<(p|div|br|ul|ol|li|h[1-6]|table|strong|b|em|i|u|a|span|blockquote)(\s[^>]*)?/?>#i', $text);
}

/**
 * Number of words in a text, tags ignored. Used to decide which explanations are long enough to be hidden by default.
 */
function crmWordCount(string $text): int
{
	$text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	if ($text === '') {
		return 0;
	}
	return count(preg_split('/\s+/u', $text) ?: array());
}

/**
 * Seconds the CRM waits for the AI service before it gives up. The setting is kept between 10 and 600; an empty or
 * invalid value means 120. Dolibarr's own limit for an outgoing call is 30 seconds, too short for a long e-mail.
 */
function crmAiTimeoutSeconds(int $setting): int
{
	if ($setting <= 0) {
		return 120;
	}
	return max(10, min(600, $setting));
}

/**
 * Why a call to the AI service returned no HTTP code at all: "timeout" when it ran up to the limit, "network" when it
 * stopped well before (name not resolved, connection refused or reset, TLS error, blocked by a proxy or a firewall).
 */
function crmAiNoHttpKind(int $elapsedSeconds, int $limitSeconds): string
{
	return ($elapsedSeconds >= $limitSeconds - 1) ? 'timeout' : 'network';
}

/**
 * Day of a sequence on which something happened: 0 on the day of the enrolment, 3 three days (72 hours) after it. This is
 * the number of the delay of a step, counted from the enrolment as crmDueTimestamp() does.
 */
function crmSequenceDay(int $enrolTs, int $eventTs): int
{
	if ($eventTs <= $enrolTs) {
		return 0;
	}
	return intdiv($eventTs - $enrolTs, 86400);
}

/**
 * Who gets a copy of a message sent from the CRM. The archive mailbox gets one for a message about a deal, as Cc or Bcc
 * by the setting; the sender may ask for a hidden copy to their own address, always as Bcc. An address that would get
 * two copies, the sender's own being the archive mailbox for instance, gets one. The archive setting is used as the
 * administrator wrote it (it may list several addresses); the address of the sender must be a valid one, or no copy is made.
 *
 * @param string $archive      Archive mailbox, empty for none
 * @param string $archiveCopy  'cc' to show the archive mailbox, anything else for a hidden copy
 * @param string $mine         Address of the sender for a hidden copy to themselves, empty for none
 * @param bool   $aboutDeal    True when the message belongs to a deal (only then is the archive mailbox copied)
 * @return array{cc:string,bcc:string} Comma separated addresses
 */
function crmCopyRecipients(string $archive, string $archiveCopy, string $mine, bool $aboutDeal): array
{
	$cc = array();
	$bcc = array();
	$archive = trim($archive);
	if ($aboutDeal && $archive !== '') {
		if ($archiveCopy === 'cc') {
			$cc[] = $archive;
		} else {
			$bcc[] = $archive;
		}
	}
	$mine = trim($mine);
	if ($mine !== '' && filter_var($mine, FILTER_VALIDATE_EMAIL)) {
		$already = false;
		foreach (array_merge($cc, $bcc) as $listed) {
			foreach (preg_split('/[\s,;]+/', $listed) ?: array() as $address) {
				if (strtolower(trim($address, '<>')) === strtolower($mine)) {
					$already = true;
				}
			}
		}
		if (!$already) {
			$bcc[] = $mine;
		}
	}
	return array('cc' => implode(',', $cc), 'bcc' => implode(',', $bcc));
}

