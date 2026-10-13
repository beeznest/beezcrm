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
 * \file    sequence_card.php
 * \ingroup crm
 * \brief   Steps, enrolments and the send journal of one sequence.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/sequence.class.php';
crmStartPage();

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$sequence = new Sequence($db);
if ($sequence->fetch($id) <= 0) {
	accessforbidden();
}
$back = $_SERVER['PHP_SELF'].'?id='.$id;

if ($action === 'addstep') {
	crmRequireRight('sequence');
	$kind = GETPOST('stepaction', 'aZ09');
	if (!in_array($kind, array('email', 'call', 'task'), true)) {
		$kind = 'email';
	}
	if ($sequence->addStep($kind, GETPOSTINT('delay_days'), GETPOST('subject', 'alphanohtml'), GETPOST('body', 'restricthtml')) <= 0) {
		setEventMessages($sequence->error, null, 'errors');
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'delstep') {
	crmRequireRight('sequence');
	$sequence->deleteStep(GETPOSTINT('stepid'));
	header('Location: '.$back);
	exit;
}

if ($action === 'enrol') {
	crmRequireRight('sequence');
	require_once __DIR__.'/class/opportunity.class.php';
	$deal = new Opportunity($db);
	$dealId = GETPOSTINT('opportunityid');
	if ($deal->fetch($dealId) <= 0 || !crmCanReadOwner($user, (int) $deal->fk_user_resp)) {
		setEventMessages($langs->trans('CrmNoRows'), null, 'errors');
	} elseif ($sequence->enrol($dealId) <= 0) {
		setEventMessages($sequence->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'stop' || $action === 'replied') {
	crmRequireRight('sequence');
	$reason = ($action === 'replied') ? 'replied' : 'manual';
	$sequence->stopEnrolment(GETPOSTINT('enrolid'), $reason);
	header('Location: '.$back);
	exit;
}

$steps = $sequence->fetchSteps();
$enrolments = $sequence->fetchEnrolments();
$log = $sequence->fetchLog();

llxHeader('', $sequence->ref, '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($sequence->ref.' — '.$sequence->label, '', 'fa-handshake');
print '<p>'.$langs->trans('CrmActive').': '.((int) $sequence->active ? $langs->trans('Yes') : $langs->trans('No')).'</p>';
print '<p class="opacitymedium">'.$langs->trans('CrmSequenceIntro').'</p>';
print '<p class="opacitymedium">'.$langs->trans('CrmPlaceholders').'</p>';

print '<h2>'.$langs->trans('CrmSteps').'</h2>';
print '<p class="opacitymedium">'.$langs->trans('CrmStepsHelp').'</p>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>#</td><td>'.$langs->trans('CrmKind').'</td><td>'.$langs->trans('CrmDelayDays').'</td><td>'.$langs->trans('CrmSubject').'</td><td></td></tr>';
foreach ($steps as $step) {
	print '<tr class="oddeven">';
	print '<td>'.(int) $step->position.'</td>';
	print '<td>'.dol_escape_htmltag((string) $step->action).'</td>';
	print '<td>'.(int) $step->delay_days.'</td>';
	print '<td>'.dol_escape_htmltag((string) $step->subject).'</td><td>';
	if ($user->hasRight('crm', 'sequence')) {
		print '<a href="'.$back.'&action=delstep&stepid='.(int) $step->rowid.'&token='.newToken().'">'.$langs->trans('Delete').'</a>';
	}
	print '</td></tr>';
}
print '</table>';

if ($user->hasRight('crm', 'sequence')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addstep">';
	print '<input type="hidden" name="id" value="'.((int) $sequence->id).'">';
	print '<select name="stepaction"><option value="email">'.$langs->trans('CrmEmail').'</option><option value="call">'.$langs->trans('CrmCall').'</option><option value="task">'.$langs->trans('CrmTask').'</option></select> ';
	print '<input type="number" name="delay_days" value="0" min="0" class="width50"> '.$langs->trans('CrmDays').' ';
	print '<input name="subject" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('CrmSubject')).'"> ';
	print '<input name="body" class="minwidth300" placeholder="'.dol_escape_htmltag($langs->trans('CrmBody')).'"> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmAddStep').'">';
	print '</form>';
}

print '<h2>'.$langs->trans('CrmEnrolments').'</h2>';
print '<p class="opacitymedium">'.$langs->trans('CrmEnrolmentsHelp').'</p>';
if ($user->hasRight('crm', 'sequence')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="enrol">';
	print '<input type="hidden" name="id" value="'.((int) $sequence->id).'">';
	print $langs->trans('CrmOpportunity').' <input type="number" name="opportunityid" min="1"> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmEnrol').'">';
	print '</form>';
}
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('CrmStatus').'</td><td>'.$langs->trans('CrmCurrentStep').'</td><td>'.$langs->trans('CrmDateNext').'</td><td>'.$langs->trans('CrmStopReason').'</td><td></td></tr>';
foreach ($enrolments as $enrol) {
	$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $enrol->fk_opportunity;
	print '<tr class="oddeven">';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $enrol->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $enrol->status).'</td>';
	print '<td>'.(int) $enrol->current_step.'</td>';
	print '<td>'.dol_print_date($db->jdate($enrol->date_next), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag((string) $enrol->stop_reason).'</td><td>';
	if ($user->hasRight('crm', 'sequence') && (string) $enrol->status === 'running') {
		print '<a href="'.$back.'&action=stop&enrolid='.(int) $enrol->rowid.'&token='.newToken().'">'.$langs->trans('CrmStop').'</a> ';
		print '<a href="'.$back.'&action=replied&enrolid='.(int) $enrol->rowid.'&token='.newToken().'">'.$langs->trans('CrmMarkReplied').'</a>';
	}
	print '</td></tr>';
}
print '</table>';

print '<h2>'.$langs->trans('CrmJournal').'</h2>';
print '<p class="opacitymedium">'.$langs->trans('CrmJournalHelp').'</p>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmEmail').'</td><td>'.$langs->trans('CrmSubject').'</td><td>'.$langs->trans('CrmStatus').'</td><td>'.$langs->trans('Error').'</td></tr>';
foreach ($log as $line) {
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($line->date_send), 'dayhour').'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->email).'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->subject).'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->status).'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->error_msg).'</td>';
	print '</tr>';
}
print '</table>';

llxFooter();
$db->close();
