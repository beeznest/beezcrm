<?php
/* Copyright (C) 2026 CRM contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

/**
 * \file    optout.php
 * \ingroup crm
 * \brief   From v1.2.0. PUBLIC page, no login: the "Not interested, don't contact me again soon" link of an e-mail.
 *
 * It accepts one parameter, `h` (64 lowercase hexadecimal characters), and nothing else: a GET with that query alone, or a POST
 * whose body is that field alone. Anything else gets the same neutral "not valid" page. It prints no data of the deal or of the
 * prospect, starts no session, sets no cookie and loads no menu, script or external resource. All rules are in CrmOptOut.
 *
 * By default a GET only shows a confirmation button, and the POST records the request (CRM_OPTOUT_CONFIRM): mail scanners open
 * every link of a message and must not close deals by themselves.
 */

// No login, no session, no CSRF token (there is no session to tie one to), no menu, no Dolibarr page layout.
define('NOLOGIN', 1);
define('NOSESSION', 1);
define('NOCSRFCHECK', 1);
define('NOTOKENRENEWAL', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 */

require_once __DIR__.'/class/crmoptout.class.php';

/**
 * Print a complete page and stop. $body is built from fixed texts only, never from the request.
 */
function crmOptOutPage(int $status, string $title, string $message, string $form = ''): void
{
	$nonce = bin2hex(random_bytes(12));
	http_response_code($status);
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: no-store, max-age=0');
	header('Referrer-Policy: no-referrer');
	header('X-Robots-Tag: noindex, nofollow, noarchive');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: DENY');
	header("Content-Security-Policy: default-src 'none'; style-src 'nonce-".$nonce."'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
	print '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	print '<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer"><title>'.dol_escape_htmltag($title).'</title>';
	print '<style nonce="'.$nonce.'">body{font-family:sans-serif;background:#f4f4f4;color:#222;margin:0}main{max-width:32em;margin:12vh auto;background:#fff;padding:2em;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.2)}h1{font-size:1.3em;margin-top:0}input[type=submit]{font-size:1em;padding:.6em 1.2em;cursor:pointer}</style>';
	print '</head><body><main><h1>'.dol_escape_htmltag($title).'</h1><p>'.dol_escape_htmltag($message).'</p>'.$form.'</main></body></html>';
	exit;
}

if (!isModEnabled('crm')) {
	http_response_code(404);
	exit;
}
$langs->load('crm@crm');

$request = crmOptOutRequest($_GET, $_POST, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($request === null) {
	crmOptOutPage(404, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutInvalid'));
}

$optOut = new CrmOptOut($db);
$token = $request['hash'];
$method = strtoupper((string) $_SERVER['REQUEST_METHOD']);

if (!$request['post'] && getDolGlobalInt('CRM_OPTOUT_CONFIRM', 1)) {
	// Opening the link only asks for a confirmation. Nothing is changed by a GET.
	$row = $optOut->find($token);
	if ($row === null) {
		crmOptOutPage(404, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutInvalid'));
	}
	if ($row->date_used !== null) {
		crmOptOutPage(200, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutAlready'));
	}
	$form = '<form method="POST" action="optout.php"><input type="hidden" name="h" value="'.$token.'"><input type="submit" value="'.dol_escape_htmltag($langs->transnoentitiesnoconv('CrmOptOutConfirmButton')).'"></form>';
	crmOptOutPage(200, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutConfirmText'), $form);
}
if ($method === 'HEAD') {
	crmOptOutPage(200, $langs->transnoentitiesnoconv('CrmOptOutTitle'), '');
}

switch ($optOut->record($token)) {
	case 'recorded':
		crmOptOutPage(200, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutDone'));
		// no break
	case 'already':
		crmOptOutPage(200, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutAlready'));
		// no break
	case 'invalid':
		crmOptOutPage(404, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutInvalid'));
		// no break
	default:
		crmOptOutPage(500, $langs->transnoentitiesnoconv('CrmOptOutTitle'), $langs->transnoentitiesnoconv('CrmOptOutError'));
}
