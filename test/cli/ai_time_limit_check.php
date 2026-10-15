<?php
declare(strict_types=1);

/**
 * \file    test/cli/ai_time_limit_check.php
 * \ingroup crm
 * \brief   The time limit the CRM puts on a call to the AI service: raised for that call alone, restored afterwards,
 *          never lower than Dolibarr's, and a failure without HTTP code is explained. No call leaves the machine.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/crmaireply.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
$reply = new CrmAiReply($db);
$seen = static function () use ($conf) {
	return (string) ($conf->global->MAIN_USE_RESPONSE_TIMEOUT ?? 'unset');
};

$conf->global->CRM_AI_TIMEOUT = '90';
$conf->global->MAIN_USE_RESPONSE_TIMEOUT = '30';
$inside = '';
$reply->runWithTimeLimit(static function () use (&$inside, $seen) {
	$inside = $seen();
	return 'text';
});
crmCliCheck('the limit is raised to the CRM setting during the call', $inside === '90');
crmCliCheck('and put back after it', $seen() === '30');

unset($conf->global->MAIN_USE_RESPONSE_TIMEOUT);
$reply->runWithTimeLimit(static function () use (&$inside, $seen) {
	$inside = $seen();
	return 'text';
});
crmCliCheck('a Dolibarr setting that was not set is not set afterwards', $inside === '90' && $seen() === 'unset');

$conf->global->MAIN_USE_RESPONSE_TIMEOUT = '300';
$reply->runWithTimeLimit(static function () use (&$inside, $seen) {
	$inside = $seen();
	return 'text';
});
crmCliCheck('a higher limit set for Dolibarr is kept', $inside === '300' && $seen() === '300');

$conf->global->MAIN_USE_RESPONSE_TIMEOUT = '30';
try {
	$reply->runWithTimeLimit(static function () {
		throw new RuntimeException('boom');
	});
} catch (RuntimeException $e) {
	// expected
}
crmCliCheck('the limit is put back when the call throws', $seen() === '30');

$answer = $reply->runWithTimeLimit(static function () {
	return array('error' => true, 'message' => 'API request failed. No http received');
});
crmCliCheck('a failure without HTTP code is explained with the time and the limit', is_array($answer) && strpos($answer['message'], 'No http received') === false && strpos($answer['message'], '90') !== false);

$answer = $reply->runWithTimeLimit(static function () {
	return array('error' => true, 'message' => 'Invalid API key', 'code' => 401);
});
crmCliCheck('any other failure is left as it is', $answer['message'] === 'Invalid API key');

crmCliCheck('a text answer is returned as it is', $reply->runWithTimeLimit(static fn() => 'Hello') === 'Hello');

crmCliFinish();
