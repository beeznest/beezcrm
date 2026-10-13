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
