<?php
declare(strict_types=1);

/**
 * \file    test/cli/crm_cli_bootstrap.inc.php
 * \ingroup crm
 * \brief   Shared start of the command-line checks: loads Dolibarr without a login, opens a transaction, counts results.
 *
 * Usage: php test/cli/<check>.php /path/to/dolibarr/htdocs
 * (or set DOLIBARR_HTDOCS). The module must be enabled and the database reachable. Every check runs inside a
 * transaction that is rolled back at the end, so nothing is kept; run it on a development instance anyway.
 */

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$crmCliHtdocs = (string) ($argv[1] ?? getenv('DOLIBARR_HTDOCS') ?: '');
if ($crmCliHtdocs === '' || !is_file(rtrim($crmCliHtdocs, '/').'/main.inc.php')) {
	fwrite(STDERR, "Usage: php ".basename((string) $argv[0])." /path/to/dolibarr/htdocs (or set DOLIBARR_HTDOCS)\n");
	exit(2);
}
$crmCliHtdocs = rtrim($crmCliHtdocs, '/');

foreach (array('NOLOGIN', 'NOSESSION', 'NOCSRFCHECK', 'NOREQUIREMENU', 'NOREQUIREHTML', 'NOREQUIREAJAX') as $crmCliConst) {
	define($crmCliConst, 1);
}
$_SERVER['DOCUMENT_ROOT'] = $crmCliHtdocs;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = (string) (getenv('DOLIBARR_HOST') ?: 'localhost');
chdir($crmCliHtdocs);
require $crmCliHtdocs.'/main.inc.php';

require_once __DIR__.'/../../lib/crm.lib.php';
crmLoadLangs();

/** @var DoliDB $db */
$user = new User($db);
$user->fetch(1);
$user->loadRights();

$crmCliFailures = 0;

/**
 * Print one result.
 */
function crmCliCheck(string $name, bool $ok): void
{
	global $crmCliFailures;
	print ($ok ? 'PASS ' : 'FAIL ').$name."\n";
	if (!$ok) {
		$crmCliFailures++;
	}
}

/**
 * Undo everything the check did and exit with a status for the shell.
 */
function crmCliFinish(): void
{
	global $db, $crmCliFailures;
	$db->rollback();
	print ($crmCliFailures === 0 ? 'All checks passed' : $crmCliFailures.' check(s) failed')."\n";
	exit($crmCliFailures === 0 ? 0 : 1);
}
