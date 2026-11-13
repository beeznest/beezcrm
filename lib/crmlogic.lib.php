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
	// A deal marker ("[O2026-0001]", "[OPP2026-0001]") is not part of what the subject says: a draft has none, the copy of a message
	// written in a mail client has one, and they must still match.
	$subject = trim((string) preg_replace('/\s*\[(?:OPP|O)[0-9]{4}-[0-9]+\]/i', '', $subject));
	while (preg_match('/^(re|fw|fwd|tr|aw|wg)\s*:\s*/i', $subject, $reg)) {
		$subject = substr($subject, strlen($reg[0]));
	}
	return strtolower(trim((string) preg_replace('/\s+/', ' ', $subject)));
}

/**
 * From v1.2.0. The short marker of a deal reference, put between square brackets in the subject of a message written in a mail
 * client: "OPP2026-0001" becomes "O2026-0001", which tells the recipient nothing about the deal. A reference that does not start
 * with OPP is kept.
 */
function crmDealMarker(string $ref): string
{
	return (strncmp($ref, 'OPP', 3) === 0 && strlen($ref) > 3) ? 'O'.substr($ref, 3) : $ref;
}

/**
 * Add the short marker of the deal to a subject, once: "Offer" becomes "Offer [O2026-0001]". A subject that already carries the
 * reference ("[OPP2026-0001]", as before 1.2.0) or the marker is returned as it is.
 */
function crmSubjectWithRef(string $subject, string $ref): string
{
	$subject = trim($subject);
	if ($ref === '' || strpos($subject, '['.$ref.']') !== false || strpos($subject, '['.crmDealMarker($ref).']') !== false) {
		return $subject;
	}
	return trim($subject.' ['.crmDealMarker($ref).']');
}

/**
 * Take the reference or the marker of a deal out of a subject: a message sent by the platform carries the deal in its headers
 * (Message-ID, X-Dolibarr-TRACKID), so its subject does not need to show it.
 */
function crmStripDealMarker(string $subject, string $ref): string
{
	if ($ref === '') {
		return trim($subject);
	}
	$out = str_replace(array('['.$ref.']', '['.crmDealMarker($ref).']'), '', $subject);
	return trim((string) preg_replace('/[ \t]{2,}/', ' ', $out));
}

/**
 * From v1.2.0. The deal references a list of bracketed subject words can stand for: each word, and for a short marker such as
 * "O2026-0001" the reference "OPP2026-0001" it was made from.
 *
 * @param string[] $words Words found between square brackets
 * @return string[]
 */
function crmExpandDealMarkers(array $words): array
{
	$refs = array();
	foreach ($words as $word) {
		if (!in_array($word, $refs, true)) {
			$refs[] = $word;
		}
		if (preg_match('/^O([0-9]{4}-[0-9]+)$/D', $word, $reg) && !in_array('OPP'.$reg[1], $refs, true)) {
			$refs[] = 'OPP'.$reg[1];
		}
	}
	return $refs;
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

/**
 * True when a stage comes before another one on the board: stages are ordered by position, and by id for the same position.
 */
function crmStageComesBefore(int $position, int $id, int $otherPosition, int $otherId): bool
{
	if ($position !== $otherPosition) {
		return $position < $otherPosition;
	}
	return $id < $otherId;
}

/**
 * A value on one line: control characters (CR, LF, NUL, tabs...) become a space, runs of spaces collapse, the ends are
 * trimmed and the text is cut to $max characters. Used for what goes into a mail header, a label shown in a title or an
 * event: a line break there would add a header or a line.
 */
function crmSingleLine(string $value, int $max = 0): string
{
	$value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
	$value = (string) preg_replace('/ {2,}/', ' ', $value);
	if ($max > 0 && mb_strlen($value, 'UTF-8') > $max) {
		$value = rtrim(mb_substr($value, 0, $max, 'UTF-8'));
	}
	return $value;
}

/**
 * Domains of public mail services: sharing one with a customer says nothing about who a sender is.
 *
 * @return string[]
 */
function crmFreeMailDomains(): array
{
	return array('gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com', 'yahoo.fr', 'ymail.com', 'icloud.com', 'me.com', 'aol.com', 'gmx.com', 'gmx.net', 'gmx.de', 'proton.me', 'protonmail.com', 'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'orange.fr', 'free.fr', 'wanadoo.fr', 'sfr.fr', 'laposte.net', 'skynet.be', 'telenet.be', 'web.de', 't-online.de', 'qq.com', '163.com');
}

/**
 * True when the sender of a message may be the person the deal is about: the address is one of the known addresses
 * (the contact of the deal, the other contacts and the address of its third party), or it has the domain of one of them,
 * unless that domain is a public mail service. Comparison ignores case.
 *
 * @param string   $address Bare sender address
 * @param string[] $known   Known addresses
 */
function crmSenderTrusted(string $address, array $known): bool
{
	$address = strtolower(trim($address));
	if ($address === '' || strpos($address, '@') === false) {
		return false;
	}
	$domain = substr($address, (int) strrpos($address, '@') + 1);
	foreach ($known as $one) {
		$one = strtolower(trim((string) $one));
		if ($one === '' || strpos($one, '@') === false) {
			continue;
		}
		if ($one === $address) {
			return true;
		}
		$oneDomain = substr($one, (int) strrpos($one, '@') + 1);
		if ($oneDomain === $domain && !in_array($domain, crmFreeMailDomains(), true)) {
			return true;
		}
	}
	return false;
}

/**
 * True for a message a machine wrote: a bounce, an out-of-office reply, a mailing list or a bulk message, by the headers
 * RFC 3834 and the usual mail systems set. Such a message is not a person answering, so it must not stop an outreach.
 */
function crmIsAutomaticMail(string $rawHeaders): bool
{
	$autoSubmitted = strtolower(crmHeaderValue($rawHeaders, 'Auto-Submitted'));
	if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
		return true;
	}
	$precedence = strtolower(crmHeaderValue($rawHeaders, 'Precedence'));
	if (in_array($precedence, array('bulk', 'junk', 'list', 'auto_reply'), true)) {
		return true;
	}
	foreach (array('X-Autoreply', 'X-Autorespond', 'X-Auto-Response-Suppress', 'List-Id', 'List-Unsubscribe') as $name) {
		if (crmHeaderValue($rawHeaders, $name) !== '') {
			return true;
		}
	}
	$from = strtolower(crmHeaderValue($rawHeaders, 'From'));
	if (preg_match('/(^|[<\s"])(mailer-daemon|postmaster)@/', $from)) {
		return true;
	}
	$returnPath = trim(crmHeaderValue($rawHeaders, 'Return-Path'));
	return ($returnPath === '<>');
}

/**
 * True when an Authentication-Results header, added by our own mail server, says the sender passed DKIM, SPF or DMARC.
 */
function crmMailAuthPassed(string $authenticationResults): bool
{
	return (bool) preg_match('/\b(dkim|spf|dmarc)=pass\b/i', $authenticationResults);
}

/**
 * Date of a received message kept inside a believable range: never in the future, never older than ten years. A header
 * that says otherwise is replaced by the moment the message was read. 0 or less means "no usable date".
 */
function crmClampMailDate(int $date, int $now): int
{
	if ($date <= 0 || $date > $now || $date < $now - (10 * 365 * 86400)) {
		return $now;
	}
	return $date;
}

/**
 * A sliding-window rate limit. Timestamps older than the window are forgotten; the call is allowed while fewer than
 * $max remain, and then its own timestamp is added.
 *
 * @param int[] $timestamps Moments of the earlier calls
 * @return array{allowed:bool,timestamps:int[]}
 */
function crmRateAllow(array $timestamps, int $now, int $windowSeconds, int $max): array
{
	$kept = array();
	foreach ($timestamps as $ts) {
		if ((int) $ts > $now - $windowSeconds && (int) $ts <= $now) {
			$kept[] = (int) $ts;
		}
	}
	if ($max > 0 && count($kept) >= $max) {
		return array('allowed' => false, 'timestamps' => $kept);
	}
	$kept[] = $now;
	return array('allowed' => true, 'timestamps' => $kept);
}

/**
 * The value to store for a module setting, or null when it is not acceptable: switches are 0 or 1, numbers stay in a
 * range, the two modes are the ones the module knows, an address is one valid address on one line, the AI prompt is plain
 * text of bounded length. Used by the setup page and by the MCP tool, so both refuse the same things.
 *
 * @return string|null Normalised value (an empty string clears an address), null when refused or when the name is unknown
 */
function crmNormalizeSetting(string $name, string $value): ?string
{
	$value = trim($value);
	$switches = array('CRM_SEQUENCES_ON', 'CRM_DRY_RUN', 'CRM_REMINDERS_ON', 'CRM_REMINDER_DRY_RUN', 'CRM_REQUIRE_NEXT_STEP',
		'CRM_REPLY_MOVES_DEAL', 'CRM_AI_EMAIL_ASSIST', 'CRM_ARCHIVE_REQUIRE_AUTH', 'CRM_OPTOUT_CONFIRM', 'CRM_MCP_ALLOW_DELETE', 'CRM_ASSIGN_NOTIFY');
	$numbers = array('CRM_ROTTING_DAYS' => array(1, 3650), 'CRM_FORECAST_MONTHS' => array(1, 36), 'CRM_MAX_EMAILS_PER_RUN' => array(1, 500),
		'CRM_AI_TIMEOUT' => array(10, 600), 'CRM_AI_MAX_PER_HOUR' => array(1, 1000));
	if (in_array($name, $switches, true)) {
		return ($value === '0' || $value === '1') ? $value : null;
	}
	if (isset($numbers[$name])) {
		if ($value === '') {
			return '';
		}
		if (!preg_match('/^[0-9]{1,5}$/', $value) || (int) $value < $numbers[$name][0] || (int) $value > $numbers[$name][1]) {
			return null;
		}
		return (string) (int) $value;
	}
	switch ($name) {
		case 'CRM_SEQUENCE_EMAIL_MODE':
			return in_array($value, array('draft', 'send'), true) ? $value : null;
		case 'CRM_ARCHIVE_COPY':
			return in_array($value, array('bcc', 'cc'), true) ? $value : null;
		case 'CRM_MAIL_FROM':
		case 'CRM_ARCHIVE_MAILBOX':
			if ($value === '') {
				return '';
			}
			return (strlen($value) <= 254 && !preg_match('/[\x00-\x20\x7F<>,;]/', $value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) ? $value : null;
		case 'CRM_DEFAULT_TIMEZONE':
			// The name of a time zone (Europe/Brussels), or empty for none.
			return ($value === '' || (strlen($value) <= 64 && in_array($value, DateTimeZone::listIdentifiers(), true))) ? $value : null;
		case 'CRM_INTERNAL_REF_URL':
			// A base address that prefixes the internal reference of a deal, or empty for no link.
			return ($value === '' || crmInternalRefBaseValid($value)) ? $value : null;
		case 'CRM_CATEGORY_SINGLE':
			// Ids of categories of third parties: a comma separated list of numbers, or empty.
			if ($value === '') {
				return '';
			}
			return (strlen($value) <= 255 && preg_match('/^[0-9]+(,[0-9]+)*$/D', $value)) ? implode(',', crmIdList($value)) : null;
		case 'CRM_AI_EMAIL_PROMPT':
			$text = trim(strip_tags($value));
			return (mb_strlen($text, 'UTF-8') <= 4000) ? $text : null;
	}
	return null;
}


/**
 * From v1.2.0. The token of the public "not interested" link of a deal: an HMAC-SHA256 (64 lowercase hex characters) of the
 * entity and the deal id under a secret that is not in the database, so it cannot be guessed from a deal number and a leaked
 * copy of the database does not give the links. Empty when the secret or an id is missing.
 */
function crmOptOutToken(string $secret, int $entity, int $dealId): string
{
	if ($secret === '' || $entity <= 0 || $dealId <= 0) {
		return '';
	}
	return hash_hmac('sha256', 'crm-optout|'.$entity.'|'.$dealId, $secret);
}

/**
 * True for exactly what crmOptOutToken() produces: 64 lowercase hexadecimal characters, nothing else.
 */
function crmOptOutHashValid(string $hash): bool
{
	return (bool) preg_match('/^[a-f0-9]{64}$/D', $hash);
}

/**
 * The public page accepts one parameter, `h`, and nothing more: a GET with the query `h=<hash>` only, or a POST whose body is
 * `h=<hash>` only (and an empty query). Any other parameter, an array, another method or a hash of the wrong shape is refused.
 *
 * @param array<string,mixed> $get    $_GET
 * @param array<string,mixed> $post   $_POST
 * @param string              $method Request method
 * @return array{hash:string,post:bool}|null null when the request is not one the page accepts
 */
function crmOptOutRequest(array $get, array $post, string $method): ?array
{
	$method = strtoupper($method);
	if ($method === 'GET' || $method === 'HEAD') {
		$source = $get;
		$other = $post;
		$isPost = false;
	} elseif ($method === 'POST') {
		$source = $post;
		$other = $get;
		$isPost = true;
	} else {
		return null;
	}
	if (count($other) !== 0 || count($source) !== 1 || !array_key_exists('h', $source) || !is_string($source['h']) || !crmOptOutHashValid($source['h'])) {
		return null;
	}
	return array('hash' => $source['h'], 'post' => $isPost);
}

/**
 * Add the "not interested" link at the end of an e-mail: a separated line with the text and the address for plain text, a
 * paragraph with a link for HTML (before </body> when there is one). The text and the address are escaped for HTML.
 */
function crmOptOutFooter(string $body, string $url, string $text, bool $html): string
{
	if ($url === '' || $text === '') {
		return $body;
	}
	if (!$html) {
		return rtrim($body)."\n\n--\n".$text."\n".$url."\n";
	}
	$paragraph = '<p style="font-size:smaller;color:#666">-- <br><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($text, ENT_QUOTES, 'UTF-8').'</a></p>';
	$position = strripos($body, '</body>');
	if ($position !== false) {
		return substr($body, 0, $position).$paragraph.substr($body, $position);
	}
	return $body.$paragraph;
}

/**
 * From v1.2.0. A category colour as Dolibarr stores it (hexadecimal, 3 or 6 digits, with or without #) turned into `#rrggbb`
 * in lower case, or '' when it is not one: the value goes into a style attribute, so nothing else is let through.
 */
function crmCategoryColor(string $color): string
{
	$color = ltrim(trim($color), '#');
	if (preg_match('/^[0-9a-fA-F]{3}$/D', $color)) {
		$color = $color[0].$color[0].$color[1].$color[1].$color[2].$color[2];
	}
	return preg_match('/^[0-9a-fA-F]{6}$/D', $color) ? '#'.strtolower($color) : '';
}

/**
 * From v1.2.0. A name underlined with the colours of the categories of the account: one coloured band per category that has a
 * colour (three at most, side by side under the name), and the labels of all the categories in the tooltip. Without a coloured
 * category the name is returned as it is.
 *
 * @param string                              $nameHtml Name as HTML, already escaped (it can be a link)
 * @param array<int,array{label:string,color:string}> $categories Categories of the account
 */
function crmAccountMarks(string $nameHtml, array $categories): string
{
	$bars = '';
	$labels = array();
	$count = 0;
	foreach ($categories as $category) {
		$labels[] = (string) ($category['label'] ?? '');
		$color = crmCategoryColor((string) ($category['color'] ?? ''));
		if ($color !== '' && $count < 3) {
			$bars .= '<i style="background:'.$color.'"></i>';
			$count++;
		}
	}
	if ($bars === '') {
		return $nameHtml;
	}
	$title = htmlspecialchars(implode(', ', array_filter($labels, 'strlen')), ENT_QUOTES, 'UTF-8');
	return '<span class="crm-acct" title="'.$title.'"><span class="crm-acct-name">'.$nameHtml.'</span><span class="crm-acct-bars">'.$bars.'</span></span>';
}

/**
 * From v1.2.0. The ids of a comma separated list of category ids (the setting CRM_CATEGORY_SINGLE), without doubles, zeros or
 * anything that is not a number.
 *
 * @return int[]
 */
function crmIdList(string $list): array
{
	$ids = array();
	foreach (explode(',', $list) as $part) {
		$part = trim($part);
		if ($part !== '' && ctype_digit($part) && (int) $part > 0 && !in_array((int) $part, $ids, true)) {
			$ids[] = (int) $part;
		}
	}
	return $ids;
}

/**
 * From v1.2.0. The internal reference of a deal (the number of a task in another system): one line, no markup, at most 128
 * characters.
 */
function crmCleanInternalRef(string $ref): string
{
	return crmSingleLine(strip_tags($ref), 128);
}

/**
 * From v1.2.0. True for a base address that may prefix internal references: http or https, no blank, no control character, no
 * quote or angle bracket, at most 500 characters. Nothing else (no javascript: or data: address) can become a link.
 */
function crmInternalRefBaseValid(string $base): bool
{
	return strlen($base) <= 500 && preg_match('~^https?://[^\s<>"\'\x00-\x1F\x7F]+$~Di', $base) === 1 && filter_var($base, FILTER_VALIDATE_URL) !== false;
}

/**
 * From v1.2.0. The address of an internal reference: the base address of the setting followed by the reference (URL-encoded, so
 * it cannot add anything to the address), or '' when there is no reference, no base or the base is not valid.
 */
function crmInternalRefUrl(string $base, string $ref): string
{
	$base = trim($base);
	$ref = crmCleanInternalRef($ref);
	if ($base === '' || $ref === '' || !crmInternalRefBaseValid($base)) {
		return '';
	}
	return $base.rawurlencode($ref);
}
