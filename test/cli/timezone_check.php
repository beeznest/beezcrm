<?php
declare(strict_types=1);

/**
 * \file    test/cli/timezone_check.php
 * \ingroup crm
 * \brief   From v1.2.0. The time zone of the browser reaches the session when Dolibarr has none (cookie crm_tz, checked against the
 *          real time zone names), so the hour proposed for a next step is the local one. Changes nothing in the database.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';

$session = isset($_SESSION) ? $_SESSION : null;
$cookie = isset($_COOKIE) ? $_COOKIE : array();

$_SESSION = array('dol_tz_string' => 'Europe/Paris');
$_COOKIE = array('crm_tz' => 'Asia/Tokyo');
crmCliCheck('a session that has a time zone keeps it', crmEnsureSessionTimezone() === true && $_SESSION['dol_tz_string'] === 'Europe/Paris');

$_SESSION = array();
$_COOKIE = array('crm_tz' => 'Asia/Tokyo');
crmCliCheck('without a zone in the session, the one of the browser cookie is used', crmEnsureSessionTimezone() === true && $_SESSION['dol_tz_string'] === 'Asia/Tokyo');

foreach (array('Mars/Phobos', 'asia/tokyo', 'UTC; DROP', str_repeat('a', 100), '', '../../etc', '<script>') as $bad) {
	$_SESSION = array();
	$_COOKIE = array('crm_tz' => $bad);
	crmCliCheck('a cookie that is not a time zone name is ignored ('.substr($bad, 0, 20).')', crmEnsureSessionTimezone() === false && empty($_SESSION['dol_tz_string']) && $GLOBALS['crmTimezoneMissing'] === true);
}
$_SESSION = array();
$_COOKIE = array('crm_tz' => array('Asia/Tokyo'));
crmCliCheck('an array in the cookie is ignored', crmEnsureSessionTimezone() === false && empty($_SESSION['dol_tz_string']));
// A session that says UTC (a browser that does not tell its zone) is treated like one with no zone.
$conf->global->CRM_DEFAULT_TIMEZONE = '';
$_SESSION = array('dol_tz_string' => 'UTC');
$_COOKIE = array('crm_tz' => 'Asia/Tokyo');
crmCliCheck('a session that says UTC takes the real zone of the browser cookie', crmEnsureSessionTimezone() === true && $_SESSION['dol_tz_string'] === 'Asia/Tokyo');
$_SESSION = array('dol_tz_string' => 'UTC');
$_COOKIE = array('crm_tz' => 'UTC');
crmCliCheck('UTC from both stays UTC when no default is set', crmEnsureSessionTimezone() === false && $_SESSION['dol_tz_string'] === 'UTC');
$conf->global->CRM_DEFAULT_TIMEZONE = 'Europe/Brussels';
$_SESSION = array('dol_tz_string' => 'UTC');
$_COOKIE = array('crm_tz' => 'UTC');
crmCliCheck('the default zone of the setup is used when the browser says UTC', crmEnsureSessionTimezone() === true && $_SESSION['dol_tz_string'] === 'Europe/Brussels');
$_SESSION = array();
$_COOKIE = array();
crmCliCheck('and when the browser said nothing yet', crmEnsureSessionTimezone() === true && $_SESSION['dol_tz_string'] === 'Europe/Brussels');
$_SESSION = array('dol_tz_string' => 'America/Lima');
crmCliCheck('a real zone of the session is never replaced by the default', crmEnsureSessionTimezone() === true && $_SESSION['dol_tz_string'] === 'America/Lima');
$conf->global->CRM_DEFAULT_TIMEZONE = 'Not/AZone';
$_SESSION = array();
$_COOKIE = array();
crmCliCheck('a default that is not a time zone is ignored', crmEnsureSessionTimezone() === false && empty($_SESSION['dol_tz_string']));
$conf->global->CRM_DEFAULT_TIMEZONE = '';
crmCliCheck('the setting accepts a zone name or nothing', crmNormalizeSetting('CRM_DEFAULT_TIMEZONE', 'Europe/Brussels') === 'Europe/Brussels' && crmNormalizeSetting('CRM_DEFAULT_TIMEZONE', '') === '' && crmNormalizeSetting('CRM_DEFAULT_TIMEZONE', 'Europe/Nowhere') === null && crmNormalizeSetting('CRM_DEFAULT_TIMEZONE', 'UTC; x') === null);
$_SESSION = array();
$_COOKIE = array();
crmCliCheck('no cookie: the session stays without a zone and the page will ask the browser', crmEnsureSessionTimezone() === false && $GLOBALS['crmTimezoneMissing'] === true);

if ($session === null) {
	unset($_SESSION);
} else {
	$_SESSION = $session;
}
$_COOKIE = $cookie;
crmCliFinish();
