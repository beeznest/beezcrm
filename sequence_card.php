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

// The plain-text preview of the editor is an ajax call to this page: it must not rotate the CSRF token the open form holds.
if (isset($_POST['action']) && in_array($_POST['action'], array('previewtext', 'aitemplate'), true)) {
	define('NOTOKENRENEWAL', '1');
}

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/sequence.class.php';
require_once __DIR__.'/class/crmaireply.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
crmStartPage();

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$sequence = new Sequence($db);
if ($sequence->fetch($id) <= 0) {
	accessforbidden();
}
$back = $_SERVER['PHP_SELF'].'?id='.$id;

if ($action === 'previewtext') {
	// Plain-text version of the HTML in the editor, made by the function that prepares what is sent.
	top_httphead('application/json');
	print json_encode(array('text' => crmHtmlToText(GETPOST('html', 'restricthtml'))));
	exit;
}

$aiStepAvailable = CrmAiReply::isAvailable() && CrmAiReply::isConfigured() && $user->hasRight('crm', 'sequence');

if ($action === 'aitemplate') {
	// Ajax: a text for an e-mail step, written or improved by the AI service. Nothing is saved: the page puts the answer in
	// the text box and the user decides whether to add the step.
	top_httphead('application/json');
	$result = array('ok' => false, 'error' => '');
	if (!$aiStepAvailable) {
		$result['error'] = $langs->transnoentitiesnoconv('CrmAiStepOff');
	} else {
		$aiFormat = (GETPOST('format', 'aZ09') === 'html') ? 'html' : 'text';
		$aiSubject = trim(GETPOST('subject', 'nohtml'));
		if ($aiSubject === '') {
			$result['error'] = $langs->transnoentitiesnoconv('CrmAiStepNeedSubject');
		} else {
			$aiReply = new CrmAiReply($db);
			$aiText = $aiReply->suggestStepTemplate($aiSubject, ($aiFormat === 'html') ? GETPOST('existing', 'restricthtml') : GETPOST('existing', 'nohtml'), $aiFormat);
			if ($aiText === null) {
				$result['error'] = (strpos($aiReply->error, 'CrmError') === 0) ? $langs->transnoentitiesnoconv($aiReply->error) : $langs->transnoentitiesnoconv('CrmAiError', $aiReply->error);
			} else {
				$result = array('ok' => true, 'text' => $aiText);
			}
		}
	}
	print json_encode($result);
	exit;
}

if ($action === 'updatesequence') {
	crmRequireRight('sequence');
	$sequence->ref = GETPOST('ref', 'nohtml');
	$sequence->label = GETPOST('label', 'nohtml');
	$sequence->active = GETPOSTINT('active') ? 1 : 0;
	$sequence->stop_on_reply = GETPOSTINT('stop_on_reply') ? 1 : 0;
	$sequence->stop_on_won = GETPOSTINT('stop_on_won') ? 1 : 0;
	$sequence->stop_on_lost = GETPOSTINT('stop_on_lost') ? 1 : 0;
	if ($sequence->update() <= 0) {
		setEventMessages(crmErrorText($sequence->error !== '' ? $sequence->error : 'Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
		header('Location: '.$back);
		exit;
	}
}

/**
 * The fields of the step form: kind, delay, subject, text and mode. The text of an e-mail step is plain or HTML, one or
 * the other, as the Format choice says; the other kinds have none.
 *
 * @return array{kind:string,delay:int,subject:string,body:string,mode:string}
 */
$readStepInput = function (): array {
	$kind = GETPOST('stepaction', 'aZ09');
	if (!in_array($kind, array('email', 'call', 'task'), true)) {
		$kind = 'email';
	}
	$body = '';
	if ($kind === 'email') {
		$body = (GETPOST('format', 'aZ09') === 'html') ? GETPOST('bodyhtml', 'restricthtml') : GETPOST('body', 'nohtml');
	}
	return array('kind' => $kind, 'delay' => GETPOSTINT('delay_days'), 'subject' => GETPOST('subject', 'nohtml'), 'body' => $body, 'mode' => GETPOST('send_mode', 'aZ09'));
};

if ($action === 'addstep') {
	crmRequireRight('sequence');
	$input = $readStepInput();
	if ($sequence->addStep($input['kind'], $input['delay'], $input['subject'], $input['body'], $input['mode']) <= 0) {
		setEventMessages(crmErrorText($sequence->error), null, 'errors');
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'updatestep') {
	crmRequireRight('sequence');
	$input = $readStepInput();
	if ($sequence->updateStep(GETPOSTINT('stepid'), $input['kind'], $input['delay'], $input['subject'], $input['body'], $input['mode']) <= 0) {
		setEventMessages(crmErrorText($sequence->error !== '' ? $sequence->error : 'Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'confirm_delstep' && GETPOST('confirm', 'alpha') === 'yes') {
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
		setEventMessages(crmErrorText($sequence->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'stop' || $action === 'replied') {
	crmRequireRight('sequence');
	if ($action === 'replied') {
		// The deal also moves to the reply stage of its pipeline.
		$sequence->markReplied($user, GETPOSTINT('enrolid'));
	} else {
		$sequence->stopEnrolment(GETPOSTINT('enrolid'), 'manual');
	}
	header('Location: '.$back);
	exit;
}

$steps = $sequence->fetchSteps();
$enrolments = $sequence->fetchEnrolments();
$log = $sequence->fetchLog();

llxHeader('', $sequence->ref, '', '', 0, 0, array('/crm/js/crm_format_editor.js'), array('/crm/css/crm.css'));
print load_fiche_titre($sequence->ref.' — '.$sequence->label, '', 'fa-handshake');
$form = new Form($db);
if ($action === 'askdelstep' && $user->hasRight('crm', 'sequence') && $sequence->fetchStep(GETPOSTINT('stepid')) !== null) {
	$waiting = $sequence->countWaitingOnStep(GETPOSTINT('stepid'));
	$question = $langs->trans('CrmConfirmDeleteStep').($waiting > 0 ? ' '.$langs->trans('CrmConfirmDeleteStepWaiting', $waiting) : '');
	print $form->formconfirm($back.'&stepid='.GETPOSTINT('stepid'), $langs->trans('Delete'), $question, 'confirm_delstep', '', 0, 1);
}
if ($user->hasRight('crm', 'sequence')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="updatesequence">';
	print '<input type="hidden" name="id" value="'.((int) $sequence->id).'">';
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td><input name="ref" class="minwidth200" maxlength="32" value="'.dol_escape_htmltag($sequence->ref).'" required></td></tr>';
	print '<tr><td>'.$langs->trans('CrmLabel').'</td><td><input name="label" class="minwidth300" value="'.dol_escape_htmltag($sequence->label).'" required></td></tr>';
		print '<tr><td>'.$langs->trans('CrmActive').'</td><td><label><input type="checkbox" name="active" value="1"'.($sequence->active ? ' checked' : '').'> '.$langs->trans('Yes').'</label> '.crmHelp($langs->trans('CrmSequenceActiveHelp'), 'span').'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmStopRules').'</td><td>';
	print '<label><input type="checkbox" name="stop_on_reply" value="1"'.($sequence->stop_on_reply ? ' checked' : '').'> '.$langs->trans('CrmStopOnReply').'</label><br>';
	print '<label><input type="checkbox" name="stop_on_won" value="1"'.($sequence->stop_on_won ? ' checked' : '').'> '.$langs->trans('CrmStopOnWon').'</label><br>';
	print '<label><input type="checkbox" name="stop_on_lost" value="1"'.($sequence->stop_on_lost ? ' checked' : '').'> '.$langs->trans('CrmStopOnLost').'</label>';
	print '</td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Update').'"></div>';
	print '</form>';
	print crmHelp($langs->trans('CrmSequenceUpdateHelp'));
} else {
	print '<p>'.$langs->trans('CrmActive').': '.((int) $sequence->active ? $langs->trans('Yes') : $langs->trans('No')).'</p>';
}
print crmHelp($langs->trans('CrmSequenceIntro'));
print crmHelp($langs->trans('CrmPlaceholders'));

print '<h2>'.$langs->trans('CrmSteps').'</h2>';
print crmHelp($langs->trans('CrmStepsHelp'));
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>#</td><td>'.$langs->trans('CrmKind').'</td><td>'.$langs->trans('CrmDelayDays').'</td><td>'.$langs->trans('CrmSubject').'</td><td>'.$langs->trans('CrmStepMode').'</td><td></td></tr>';
foreach ($steps as $step) {
	print '<tr class="oddeven">';
	print '<td>'.(int) $step->position.'</td>';
	print '<td>'.dol_escape_htmltag((string) $step->action).((string) $step->action === 'email' && crmLooksLikeHtml((string) $step->body) ? ' <span class="opacitymedium">('.$langs->trans('CrmStepHtml').')</span>' : '').'</td>';
	print '<td>'.(int) $step->delay_days.'</td>';
	print '<td>'.dol_escape_htmltag((string) $step->subject).'</td>';
	print '<td>';
	if ((string) $step->action === 'email') {
		$stepMode = (string) ($step->send_mode ?? '');
		if ($stepMode === 'draft' || $stepMode === 'send') {
			print $langs->trans($stepMode === 'send' ? 'CrmStepModeSend' : 'CrmStepModeDraft');
		} else {
			print '<span class="opacitymedium">'.$langs->trans('CrmStepModeDefault', $langs->transnoentitiesnoconv(crmSequenceDraftMode() ? 'CrmStepModeDraft' : 'CrmStepModeSend')).'</span>';
		}
	}
	print '</td><td>';
	if ($user->hasRight('crm', 'sequence')) {
		print crmIconLink($back.'&action=editstep&stepid='.(int) $step->rowid.'&token='.newToken().'#crm-step-form', 'edit', $langs->trans('Modify'));
		print crmIconLink($back.'&action=askdelstep&stepid='.(int) $step->rowid.'&token='.newToken(), 'delete', $langs->trans('Delete'));
	}
	print '</td></tr>';
}
print '</table>';

if ($user->hasRight('crm', 'sequence')) {
	// The same form adds a step or, with action=editstep, changes one: the step keeps its position.
	$stepEdit = null;
	if ($action === 'editstep') {
		$stepEdit = $sequence->fetchStep(GETPOSTINT('stepid'));
	}
	$isEdit = ($stepEdit !== null);
	$pfKind = $isEdit ? (string) $stepEdit->action : 'email';
	$pfDelay = $isEdit ? (int) $stepEdit->delay_days : 0;
	$pfMode = $isEdit ? (string) ($stepEdit->send_mode ?? '') : '';
	$pfSubject = $isEdit ? (string) $stepEdit->subject : '';
	$pfBody = $isEdit ? (string) $stepEdit->body : '';
	$pfFormat = crmLooksLikeHtml($pfBody) ? 'html' : 'text';
	$pfEditorHtml = ($pfFormat === 'html') ? $pfBody : crmTextToHtml($pfBody);
	$pfPlain = ($pfFormat === 'html') ? crmHtmlToText($pfBody) : $pfBody;
	if ($isEdit) {
		print '<h3 id="crm-step-form-title">'.$langs->trans('CrmEditingStep', (int) $stepEdit->position).' &nbsp; <a class="small" href="'.$back.'">'.$langs->trans('Cancel').'</a></h3>';
	}
	print '<form method="POST" id="crm-step-form" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($isEdit ? 'updatestep' : 'addstep').'">';
	print '<input type="hidden" name="id" value="'.((int) $sequence->id).'">';
	if ($isEdit) {
		print '<input type="hidden" name="stepid" value="'.((int) $stepEdit->rowid).'">';
	}
	// First line: the four short fields. Below them, on their own lines: the text of the message (an e-mail step only),
	// and the button.
	print '<div class="marginbottomonly">';
	print '<select name="stepaction" id="crm-step-kind">';
	foreach (array('email' => 'CrmEmail', 'call' => 'CrmCall', 'task' => 'CrmTask') as $kindCode => $kindKey) {
		print '<option value="'.$kindCode.'"'.($pfKind === $kindCode ? ' selected' : '').'>'.$langs->trans($kindKey).'</option>';
	}
	print '</select> ';
	print '<input type="number" name="delay_days" value="'.$pfDelay.'" min="0" class="width50"> '.$langs->trans('CrmDays').' ';
	if (crmStepModeSupported($db)) {
		print '<select name="send_mode" id="crm-step-mode" title="'.dol_escape_htmltag($langs->trans('CrmStepModeHelp')).'">';
		print '<option value=""'.($pfMode === '' ? ' selected' : '').'>'.$langs->trans('CrmStepModeDefault', $langs->transnoentitiesnoconv(crmSequenceDraftMode() ? 'CrmStepModeDraft' : 'CrmStepModeSend')).'</option>';
		print '<option value="draft"'.($pfMode === 'draft' ? ' selected' : '').'>'.$langs->trans('CrmStepModeDraft').'</option>';
		print '<option value="send"'.($pfMode === 'send' ? ' selected' : '').'>'.$langs->trans('CrmStepModeSend').'</option></select> ';
	}
	print '<input name="subject" id="crm-step-subject" class="minwidth300" value="'.dol_escape_htmltag($pfSubject).'" placeholder="'.dol_escape_htmltag($langs->trans('CrmSubject')).'">';
	print '</div>';
	print '<div id="crm-step-body" class="marginbottomonly">';
	print '<div class="marginbottomonly">'.$langs->trans('CrmFormat').' ';
	print '<label><input type="radio" name="format" value="html"'.($pfFormat === 'html' ? ' checked' : '').'> '.$langs->trans('CrmFormatHtml').'</label> &nbsp; ';
	print '<label><input type="radio" name="format" value="text"'.($pfFormat === 'text' ? ' checked' : '').'> '.$langs->trans('CrmFormatText').'</label>';
	print crmHelp($langs->trans('CrmStepFormatHelp'), 'span', true).'</div>';
	print '<div class="crm-tabs"><button type="button" id="crm-tab-html" class="crm-tab">'.$langs->trans('CrmTabHtml').'</button><button type="button" id="crm-tab-plain" class="crm-tab">'.$langs->trans('CrmTabPlain').'</button></div>';
	print '<div id="crm-pane-html" class="crm-pane">';
	$stepEditor = new DolEditor('bodyhtml', $pfEditorHtml, '', 260, 'dolibarr_mailings', 'In', true, false, true, 10, '95%');
	$stepEditor->Create(0, '', true);
	print '</div>';
	print '<div id="crm-pane-plain" class="crm-pane"><textarea name="body" id="crm-body" class="quatrevingtpercent" rows="8" placeholder="'.dol_escape_htmltag($langs->trans('CrmBody')).'">'.dol_escape_htmltag($pfPlain, 0, 1).'</textarea>';
	print crmHelp($langs->trans('CrmPlainAlternativeNote'), 'div').'</div>';
	print '</div>';
	print '<div>';
	print '<input type="submit" class="button" value="'.$langs->trans($isEdit ? 'CrmUpdateStep' : 'CrmAddStep').'"> ';
	// Disabled from the start: the script enables it when the kind is e-mail and the subject is filled.
	print '<input type="button" class="button" id="crm-ai-step" disabled value="'.dol_escape_htmltag($langs->trans('CrmAiStepSuggest')).'"'.($aiStepAvailable ? ' title="'.dol_escape_htmltag($langs->trans('CrmAiStepNeedSubject')).'"' : ' title="'.dol_escape_htmltag($langs->trans('CrmAiStepOff')).'"').'> ';
	print '<span id="crm-ai-step-msg" class="opacitymedium"></span>';
	if ($aiStepAvailable) {
		print crmHelp($langs->trans('CrmAiStepHelp'), 'span', true);
	}
	if ($isEdit) {
		print crmHelp($langs->trans('CrmEditStepHelp'), 'span', true);
	}
	print '</div>';
	$stepConfig = array(
		'formId' => 'crm-step-form', 'kindId' => 'crm-step-kind', 'onlyEmailIds' => array('crm-step-body', 'crm-step-mode'),
		'radioName' => 'format', 'htmlName' => 'bodyhtml', 'plainId' => 'crm-body',
		'paneHtmlId' => 'crm-pane-html', 'panePlainId' => 'crm-pane-plain', 'tabHtmlId' => 'crm-tab-html', 'tabPlainId' => 'crm-tab-plain',
		'noteId' => 'crm-plain-note', 'format' => $pfFormat, 'id' => (int) $sequence->id, 'url' => $_SERVER['PHP_SELF'], 'token' => newToken(),
		'tabPlain' => $langs->transnoentities('CrmTabPlain'), 'tabPlainAlt' => $langs->transnoentities('CrmTabPlainAlt'),
		'confirmToText' => $langs->transnoentities('CrmConfirmToText'),
		'ai' => array(
			'enabled' => $aiStepAvailable, 'buttonId' => 'crm-ai-step', 'messageId' => 'crm-ai-step-msg', 'subjectId' => 'crm-step-subject',
			'suggest' => $langs->transnoentities('CrmAiStepSuggest'), 'improve' => $langs->transnoentities('CrmAiStepImprove'),
			'waiting' => $langs->transnoentities('CrmAiWaiting'), 'needSubject' => $langs->transnoentities('CrmAiStepNeedSubject'),
			'needEmail' => $langs->transnoentities('CrmAiStepNeedEmail'), 'failed' => $langs->transnoentities('CrmAiError', '?'),
		),
	);
	print '<script nonce="'.dol_escape_htmltag(getNonce()).'">window.addEventListener("load", function () { CrmFormatEditor.init('.json_encode($stepConfig, JSON_HEX_TAG | JSON_HEX_AMP).'); });</script>';
	print '</form>';
}

print '<h2>'.$langs->trans('CrmEnrolments').'</h2>';
print crmHelp($langs->trans('CrmEnrolmentsHelp'));
if ($user->hasRight('crm', 'sequence')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="enrol">';
	print '<input type="hidden" name="id" value="'.((int) $sequence->id).'">';
	// Open deals the caller may see and that are not already running on this sequence, in a searchable list (select2):
	// typing filters on the reference, the title and the account name.
	require_once __DIR__.'/class/opportunity.class.php';
	$running = array();
	foreach ($enrolments as $enrol) {
		if ((string) $enrol->status === 'running') {
			$running[(int) $enrol->fk_opportunity] = true;
		}
	}
	$dealChoices = array();
	foreach ((new Opportunity($db))->fetchList($user, 'open', 0, 0, '', 1000) as $deal) {
		if (isset($running[(int) $deal->rowid])) {
			continue;
		}
		$dealChoices[(int) $deal->rowid] = $deal->ref.' — '.$deal->label.($deal->soc_name !== null && $deal->soc_name !== '' ? ' ('.$deal->soc_name.')' : '');
	}
	print $langs->trans('CrmOpportunity').' '.$form->selectarray('opportunityid', $dealChoices, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth400', 1).' ';
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
print crmHelp($langs->trans('CrmJournalHelp'));
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmOpportunity').'</td><td>'.$langs->trans('CrmLogWhyTitle').'</td><td>'.$langs->trans('CrmEmail').'</td><td>'.$langs->trans('CrmSubject').'</td><td>'.$langs->trans('CrmStatus').'</td><td>'.$langs->trans('Error').'</td><td></td></tr>';
if (count($log) === 0) {
	print '<tr class="oddeven"><td colspan="8">'.$langs->trans('CrmNoRows').'</td></tr>';
}
foreach ($log as $line) {
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($line->date_send), 'dayhour').'</td>';
	print '<td><a href="'.dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $line->fk_opportunity.'">'.dol_escape_htmltag((string) $line->deal_ref).'</a></td>';
	print '<td>'.dol_escape_htmltag(crmLogWhy($line)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->email).'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->subject).'</td>';
	print '<td>'.dol_escape_htmltag(crmLogStatusLabel((string) $line->status)).'</td>';
	print '<td>'.dol_escape_htmltag((string) $line->error_msg).'</td>';
	// The e-mail is kept for every line, sent or simulated; a line from before that is rebuilt and saved when opened.
	print '<td class="right">'.crmLogViewLink((int) $line->rowid).'</td>';
	print '</tr>';
}
print '</table>';

crmHelpToggle();
llxFooter();
$db->close();
