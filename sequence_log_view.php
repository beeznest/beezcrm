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
 * \file    sequence_log_view.php
 * \ingroup crm
 * \brief   The e-mail of one line of the sequence journal, as it was made: sent, simulated or left as a draft.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/sequence.class.php';
crmStartPage(true); // From v1.2.0: also opens for the outside agent (right "assigned")

$logId = GETPOSTINT('id');
$sequence = new Sequence($db);
$line = $sequence->fetchLogLine($logId);
// A deal whose owner the user may not read is not shown, and neither is its mail.
if ($line === null || !crmCanReadOwner($user, (int) $line->fk_user_resp, (int) $line->fk_opportunity)) {
	accessforbidden();
}
// A line written before the content was kept gets it here, and keeps it from then on.
$line = $sequence->ensureLogContent($logId) ?? $line;
$supported = crmLogContentSupported($db);
$body = $supported ? (string) $line->body : '';
$source = $supported ? (string) $line->content_source : '';
$status = (string) $line->status;

$dealUrl = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $line->fk_opportunity;
$sequenceUrl = dol_buildpath('/crm/sequence_card.php', 1).'?id='.(int) $line->fk_sequence;

llxHeader('', $langs->trans('CrmLogViewTitle'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmLogViewTitle'), '<a class="butAction" href="'.$dealUrl.'">'.$langs->trans('CrmBackToDeal').'</a>', 'fa-handshake');

if ($status === 'simulated') {
	print '<div class="info">'.$langs->trans('CrmLogSimulatedNote').'</div>';
} elseif ($status === 'draft') {
	print '<div class="info">'.$langs->trans('CrmLogDraftNote').'</div>';
} elseif ($status === 'error') {
	print '<div class="warning">'.$langs->trans('CrmLogErrorNote', dol_escape_htmltag((string) $line->error_msg)).'</div>';
}
if ($source === 'event') {
	print '<div class="info">'.$langs->trans('CrmLogEventNote').'</div>';
} elseif ($source === 'rebuilt') {
	print '<div class="warning">'.$langs->trans('CrmLogRebuiltNote').'</div>';
}

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('CrmOpportunity').'</td><td><a href="'.$dealUrl.'">'.dol_escape_htmltag((string) $line->deal_ref).'</a> '.dol_escape_htmltag((string) $line->deal_label).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmSequence').'</td><td><a href="'.$sequenceUrl.'">'.dol_escape_htmltag((string) $line->seq_ref).'</a> '.dol_escape_htmltag((string) $line->seq_label).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmLogWhyTitle').'</td><td>'.dol_escape_htmltag(crmLogWhy($line)).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmDate').'</td><td>'.dol_print_date($db->jdate($line->date_send), 'dayhour').'</td></tr>';
print '<tr><td>'.$langs->trans('CrmStatus').'</td><td>'.dol_escape_htmltag(crmLogStatusLabel($status)).'</td></tr>';
if ($supported && (string) $line->from_email !== '') {
	print '<tr><td>'.$langs->trans('CrmLogFrom').'</td><td>'.dol_escape_htmltag((string) $line->from_email).'</td></tr>';
}
print '<tr><td>'.$langs->trans('CrmLogTo').'</td><td>'.dol_escape_htmltag((string) $line->email).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmSubject').'</td><td>'.dol_escape_htmltag((string) $line->subject).'</td></tr>';
print '</table>';

print '<h2>'.$langs->trans('CrmBody').'</h2>';
if ($body !== '') {
	print '<div class="crm-mail-body">';
	if ((string) $line->body_format === 'html' || ((string) $line->body_format === '' && crmLooksLikeHtml($body))) {
		print dol_htmlwithnojs($body);
	} else {
		print dol_nl2br(dol_escape_htmltag($body, 0, 1));
	}
	print '</div>';
} else {
	print '<div class="warning">'.$langs->trans($supported ? 'CrmLogNoContent' : 'CrmLogNoContentSchema').'</div>';
}

crmHelpToggle();
llxFooter();
$db->close();
