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
 * \file    mail_file.php
 * \ingroup crm
 * \brief   From v1.2.0. Download, or preview for a PDF, of a file attached to an e-mail of a deal (written in the e-mail editor).
 *          The rights are the CRM's: the deal must be readable by the user (own deals, or all with "Read all").
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var User $user
 */

require_once __DIR__.'/class/crmmailfile.class.php';
crmStartPage();

$files = new CrmMailFile($db);
$file = $files->fetchFile($user, GETPOSTINT('id'));
if ($file === null) {
	accessforbidden();
}
$path = $files->path($file);
if (!is_file($path) || !is_readable($path)) {
	http_response_code(404);
	print 'File not found';
	exit;
}
$isPdf = (strtolower((string) pathinfo((string) $file->filename, PATHINFO_EXTENSION)) === 'pdf');
$inline = $isPdf && GETPOST('mode', 'aZ09') === 'view';
$name = str_replace(array('"', "\r", "\n", '\\'), '_', (string) $file->filename);
header('Content-Type: '.($isPdf ? 'application/pdf' : 'application/octet-stream'));
header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$name.'"');
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
