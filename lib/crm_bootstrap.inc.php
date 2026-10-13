<?php
declare(strict_types=1);

/**
 * Load Dolibarr. The module directory is outside htdocs and reached through a symlink,
 * so a relative include from the real path does not find main.inc.php.
 */

if (defined('DOL_DOCUMENT_ROOT')) {
	require_once __DIR__.'/crm.lib.php';
	return;
}

$crmCandidates = array();
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
	$crmCandidates[] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/').'/main.inc.php';
}
if (!empty($_SERVER['SCRIPT_FILENAME'])) {
	$crmWalk = (string) $_SERVER['SCRIPT_FILENAME'];
	for ($crmI = 0; $crmI < 8; $crmI++) {
		$crmDir = dirname($crmWalk);
		if ($crmDir === $crmWalk) {
			break;
		}
		$crmCandidates[] = $crmDir.'/main.inc.php';
		$crmWalk = $crmDir;
	}
}
$crmWalk = __DIR__;
for ($crmI = 0; $crmI < 8; $crmI++) {
	$crmCandidates[] = $crmWalk.'/main.inc.php';
	$crmParent = dirname($crmWalk);
	if ($crmParent === $crmWalk) {
		break;
	}
	$crmWalk = $crmParent;
}

$crmLoaded = false;
foreach ($crmCandidates as $crmCandidate) {
	if ($crmCandidate !== '' && is_file($crmCandidate)) {
		require $crmCandidate;
		$crmLoaded = true;
		break;
	}
}
if (!$crmLoaded) {
	print 'Include of main fails';
	exit(1);
}
require_once __DIR__.'/crm.lib.php';
