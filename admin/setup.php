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
 * \file    admin/setup.php
 * \ingroup crm
 * \brief   Parameters, stages, qualification, loss reasons and sources.
 */

// The on/off switches call this page by ajax: that call must not rotate the CSRF token the open form still holds.
if (isset($_POST['action']) && $_POST['action'] === 'toggle') {
	define('NOTOKENRENEWAL', '1');
}

require_once __DIR__.'/../lib/crm_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../class/pipeline.class.php';
require_once __DIR__.'/../class/stage.class.php';
require_once __DIR__.'/../class/qualification.class.php';
crmStartPage();
crmRequireRight('setup');

$tab = GETPOST('tab', 'aZ09');
if (!in_array($tab, array('parameters', 'stages', 'qualification', 'reasons', 'sources', 'about'), true)) {
	$tab = 'parameters';
}
$action = GETPOST('action', 'aZ09');

$constants = array(
	'CRM_ROTTING_DAYS' => 'CrmRottingDays',
	'CRM_FORECAST_MONTHS' => 'CrmForecastMonths',
	'CRM_MAX_EMAILS_PER_RUN' => 'CrmMaxEmails',
	'CRM_MAIL_FROM' => 'CrmMailFrom',
	'CRM_SEQUENCES_ON' => 'CrmSequencesOn',
	'CRM_SEQUENCE_EMAIL_MODE' => 'CrmSequenceEmailMode',
	'CRM_DRY_RUN' => 'CrmDryRun',
	'CRM_REMINDERS_ON' => 'CrmRemindersOn',
	'CRM_REMINDER_DRY_RUN' => 'CrmReminderDryRun',
	'CRM_REQUIRE_NEXT_STEP' => 'CrmRequireNextStep',
	'CRM_AI_EMAIL_ASSIST' => 'CrmAiEmailAssist',
	'CRM_AI_EMAIL_PROMPT' => 'CrmAiEmailPrompt',
	'CRM_ARCHIVE_MAILBOX' => 'CrmArchiveMailbox',
	'CRM_ARCHIVE_COPY' => 'CrmArchiveCopy',
);

// Settings that are only on or off are switches, saved at once. They are stored as 1 or 0, never deleted: a deleted
// constant would be recreated with its default the next time the module is enabled.
$switches = array('CRM_SEQUENCES_ON', 'CRM_DRY_RUN', 'CRM_REMINDERS_ON', 'CRM_REMINDER_DRY_RUN', 'CRM_REQUIRE_NEXT_STEP', 'CRM_AI_EMAIL_ASSIST');
$switchName = '';
$switchValue = 0;
if ($action === 'toggle') {
	$switchName = GETPOST('name', 'aZ09');
	$switchValue = GETPOSTINT('value') ? 1 : 0;
} elseif (preg_match('/^(set|del)_([A-Z0-9_]+)$/', $action, $switchMatch)) {
	$switchName = $switchMatch[2];
	$switchValue = ($switchMatch[1] === 'set') ? 1 : 0;
}
if ($switchName !== '') {
	$tokenOk = hash_equals(currentToken(), GETPOST('token', 'alphanohtml'));
	$allowed = in_array($switchName, $switches, true) && $tokenOk;
	if ($allowed) {
		dolibarr_set_const($db, $switchName, (string) $switchValue, 'chaine', 0, '', (int) $conf->entity);
	}
	if ($action === 'toggle') {
		top_httphead('application/json');
		print json_encode(array('ok' => $allowed, 'name' => $switchName, 'value' => $allowed ? $switchValue : null));
		exit;
	}
	if ($allowed) {
		setEventMessages($langs->trans('RecordSaved'), null);
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=parameters');
	exit;
}

if ($action === 'setconst') {
	foreach ($constants as $name => $label) {
		if (in_array($name, $switches, true)) {
			continue;
		}
		dolibarr_set_const($db, $name, GETPOST($name, $name === 'CRM_AI_EMAIL_PROMPT' ? 'nohtml' : 'alphanohtml'), 'chaine', 0, '', (int) $conf->entity);
	}
	setEventMessages($langs->trans('RecordSaved'), null);
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=parameters');
	exit;
}

if ($action === 'addpipeline') {
	$pipeline = new Pipeline($db);
	$newId = $pipeline->save($user, 0, GETPOST('ref', 'aZ09'), GETPOST('label', 'nohtml'), 1, GETPOSTINT('copyfrom'));
	if ($newId <= 0) {
		setEventMessages(crmErrorText($pipeline->error !== '' ? $pipeline->error : 'Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages'.($newId > 0 ? '&pipelineid='.$newId : ''));
	exit;
}

if ($action === 'savepipeline') {
	$pipeline = new Pipeline($db);
	$pipelineId = GETPOSTINT('pipelineid');
	if ($pipeline->save($user, $pipelineId, '', GETPOST('label', 'nohtml'), GETPOSTINT('active') ? 1 : 0) <= 0) {
		setEventMessages(crmErrorText($pipeline->error !== '' ? $pipeline->error : 'Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.$pipelineId);
	exit;
}

if ($action === 'savestage') {
	$stage = new Stage($db);
	$stageId = GETPOSTINT('stageid');
	if ($stageId > 0) {
		$stage->fetch($stageId);
	}
	$stage->fk_pipeline = GETPOSTINT('pipelineid');
	$stage->label = GETPOST('label', 'alphanohtml');
	$stage->position = GETPOSTINT('position');
	$stage->color = GETPOST('color', 'alphanohtml');
	if ($stage->color === '') {
		$stage->color = '#6c757d';
	}
	$stage->probability = GETPOSTINT('probability');
	$stage->rotting_days = GETPOSTINT('rotting_days');
	$stage->forecast_category = GETPOST('forecast_category', 'aZ09');
	if (!in_array($stage->forecast_category, array('pipeline', 'upside', 'commit', 'closed', 'lost'), true)) {
		$stage->forecast_category = 'pipeline';
	}
	$stage->is_won = GETPOSTINT('is_won') ? 1 : 0;
	$stage->is_lost = GETPOSTINT('is_lost') ? 1 : 0;
	$stage->active = GETPOST('active', 'aZ09') === '' ? 1 : (GETPOSTINT('active') ? 1 : 0);
	$result = ($stageId > 0) ? $stage->update() : $stage->create();
	if ($result <= 0) {
		setEventMessages($stage->error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.(int) $stage->fk_pipeline);
	exit;
}

if ($action === 'delstage') {
	$stage = new Stage($db);
	if ($stage->fetch(GETPOSTINT('stageid')) > 0 && $stage->delete() <= 0) {
		setEventMessages(crmErrorText($stage->error), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.GETPOSTINT('pipelineid'));
	exit;
}

if ($action === 'savequal') {
	$id = crmSaveQualificationItem($db, GETPOSTINT('itemid'), GETPOST('code', 'aZ09'), GETPOST('label', 'alphanohtml'), GETPOSTINT('points'), GETPOSTINT('position'), 1);
	if ($id <= 0) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=qualification');
	exit;
}

if ($action === 'savedict') {
	$which = GETPOST('which', 'aZ09');
	if ($which !== 'source' && $which !== 'loss_reason') {
		$which = 'source';
	}
	$id = crmSaveDictionary($db, $which, GETPOSTINT('itemid'), GETPOST('code', 'aZ09'), GETPOST('label', 'alphanohtml'), GETPOSTINT('position'), 1);
	if ($id <= 0) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	$target = ($which === 'source') ? 'sources' : 'reasons';
	header('Location: '.$_SERVER['PHP_SELF'].'?tab='.$target);
	exit;
}

$head = array();
foreach (array('parameters' => 'CrmParameters', 'stages' => 'CrmStages', 'qualification' => 'CrmQualification', 'reasons' => 'CrmLossReasons', 'sources' => 'CrmSources', 'about' => 'CrmAbout') as $code => $key) {
	$head[] = array($_SERVER['PHP_SELF'].'?tab='.$code, $langs->trans($key), $code);
}

llxHeader('', $langs->trans('CrmSetup'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmSetup'), '', 'fa-handshake');
print dol_get_fiche_head($head, $tab, '', -1, 'fa-handshake');

if ($tab === 'parameters') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="setconst">';
	print '<input type="hidden" name="tab" value="parameters">';
	print '<table class="noborder centpercent">';
	foreach ($constants as $name => $label) {
		print '<tr class="oddeven"><td>'.$langs->trans($label).'</td>';
		if ($name === 'CRM_SEQUENCE_EMAIL_MODE') {
			$mode = crmSequenceDraftMode() ? 'draft' : 'send';
			print '<td><select name="'.$name.'"><option value="draft"'.($mode === 'draft' ? ' selected' : '').'>'.$langs->trans('CrmSequenceEmailModeDraft').'</option>';
			print '<option value="send"'.($mode === 'send' ? ' selected' : '').'>'.$langs->trans('CrmSequenceEmailModeSend').'</option></select>';
			print crmHelp($langs->trans('CrmSequenceEmailModeHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if ($name === 'CRM_AI_EMAIL_PROMPT') {
			print '<td><textarea class="quatrevingtpercent" name="'.$name.'" rows="5">'.dol_escape_htmltag(getDolGlobalString($name), 0, 1).'</textarea>';
			print crmHelp($langs->trans('CrmAiEmailPromptHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if (in_array($name, $switches, true)) {
			$on = getDolGlobalInt($name, 0) !== 0;
			// A confirmation before the two switches that have a consequence outside the module.
			$confirmOn = ($name === 'CRM_AI_EMAIL_ASSIST') ? $langs->trans('CrmConfirmAiOn') : '';
			$confirmOff = ($name === 'CRM_DRY_RUN') ? $langs->trans('CrmConfirmDryRunOff') : (($name === 'CRM_REMINDER_DRY_RUN') ? $langs->trans('CrmConfirmReminderDryRunOff') : '');
			print '<td><a class="crm-toggle'.($on ? ' crm-toggle-on' : '').'" data-name="'.$name.'" data-value="'.($on ? 0 : 1).'"';
			print ' data-confirm-on="'.dol_escape_htmltag($confirmOn).'" data-confirm-off="'.dol_escape_htmltag($confirmOff).'"';
			print ' href="'.$_SERVER['PHP_SELF'].'?tab=parameters&action='.($on ? 'del_' : 'set_').$name.'&token='.newToken().'">';
			print '<span class="crm-sw-on">'.img_picto($langs->trans('Enabled'), 'switch_on').'</span><span class="crm-sw-off">'.img_picto($langs->trans('Disabled'), 'switch_off').'</span></a>';
			if ($name === 'CRM_AI_EMAIL_ASSIST') {
				print crmHelp($langs->trans('CrmAiEmailAssistHelp'), 'span', true);
			}
			print '</td></tr>';
			continue;
		}
		if ($name === 'CRM_ARCHIVE_COPY') {
			$copy = (getDolGlobalString($name) === 'cc') ? 'cc' : 'bcc';
			print '<td><select name="'.$name.'"><option value="bcc"'.($copy === 'bcc' ? ' selected' : '').'>'.$langs->trans('CrmArchiveCopyBcc').'</option>';
			print '<option value="cc"'.($copy === 'cc' ? ' selected' : '').'>'.$langs->trans('CrmArchiveCopyCc').'</option></select></td></tr>';
			continue;
		}
		print '<td><input class="minwidth300" name="'.$name.'" value="'.dol_escape_htmltag(getDolGlobalString($name)).'"></td></tr>';
	}
	print '</table>';
	print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
	print crmHelp($langs->trans('CrmRunNote'));
	print crmHelp($langs->trans('CrmArchiveHelp'));
	print crmHelp($langs->trans('CrmSwitchHelp'));
	$switchJs = <<<'JS'
(function () {
	var token = __TOKEN__;
	var url = __URL__;
	document.addEventListener("click", function (event) {
		var link = event.target.closest("a.crm-toggle");
		if (!link) { return; }
		event.preventDefault();
		var next = link.getAttribute("data-value") === "1" ? 1 : 0;
		var message = link.getAttribute(next === 1 ? "data-confirm-on" : "data-confirm-off");
		if (message && !window.confirm(message)) { return; }
		var body = new URLSearchParams({ token: token, action: "toggle", name: link.getAttribute("data-name"), value: String(next) });
		fetch(url, { method: "POST", body: body, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (!d.ok) { throw new Error("refused"); }
				link.classList.toggle("crm-toggle-on", next === 1);
				link.setAttribute("data-value", next === 1 ? "0" : "1");
				link.setAttribute("href", link.getAttribute("href").replace(/action=(set|del)_/, "action=" + (next === 1 ? "del_" : "set_")));
			})
			.catch(function () { window.location.reload(); });
	});
}());
JS;
	print '<script nonce="'.dol_escape_htmltag(getNonce()).'">'.str_replace(array('__TOKEN__', '__URL__'), array(json_encode(newToken()), json_encode($_SERVER['PHP_SELF'])), $switchJs).'</script>';
}

if ($tab === 'stages') {
	$pipelineId = GETPOSTINT('pipelineid');
	$pipelines = (new Pipeline($db))->fetchAll(false);
	if ($pipelineId <= 0 && count($pipelines) > 0) {
		$pipelineId = (int) $pipelines[0]->id;
	}
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="tab" value="stages">';
	print '<select name="pipelineid">';
	foreach ($pipelines as $pipeline) {
		print '<option value="'.((int) $pipeline->id).'"'.($pipeline->id === $pipelineId ? ' selected' : '').'>'.dol_escape_htmltag(crmDictLabel((string) $pipeline->label)).'</option>';
	}
	print '</select> <input type="submit" class="button" value="'.$langs->trans('CrmFilter').'"></form>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmLabel').'</td><td>'.$langs->trans('CrmPosition').'</td><td>'.$langs->trans('CrmProbability').'</td><td>'.$langs->trans('CrmForecastCategory').'</td><td></td></tr>';
	if ($pipelineId > 0) {
		foreach ((new Stage($db))->fetchAll($pipelineId) as $stage) {
			print '<tr class="oddeven"><td>'.dol_escape_htmltag(crmStageLabel((string) $stage->label)).'</td><td>'.(int) $stage->position.'</td><td>'.(int) $stage->probability.'%</td><td>'.dol_escape_htmltag($stage->forecast_category).'</td>';
			print '<td><a href="'.$_SERVER['PHP_SELF'].'?tab=stages&action=delstage&stageid='.(int) $stage->id.'&pipelineid='.$pipelineId.'&token='.newToken().'">'.$langs->trans('CrmDeleteStage').'</a></td></tr>';
		}
	}
	print '</table>';
	print '<h2>'.$langs->trans('CrmAdd').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savestage">';
	print '<input type="hidden" name="pipelineid" value="'.((int) $pipelineId).'">';
	print '<input name="label" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" required> ';
	print '<input name="position" type="number" value="0" class="width50"> ';
	print '<input name="color" value="#6c757d" class="width75"> ';
	print '<input name="probability" type="number" value="10" class="width50">% ';
	print '<input name="rotting_days" type="number" min="0" value="'.max(0, getDolGlobalInt('CRM_ROTTING_DAYS', 15)).'" class="width50"> ';
	print '<select name="forecast_category"><option>pipeline</option><option>upside</option><option>commit</option><option>closed</option><option>lost</option></select> ';
	print '<label><input type="checkbox" name="is_won" value="1"> '.$langs->trans('CrmWonStage').'</label> ';
	print '<label><input type="checkbox" name="is_lost" value="1"> '.$langs->trans('CrmLostStage').'</label> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmAdd').'">';
	print '</form>';
	if ($pipelineId > 0) {
		$selected = new Pipeline($db);
		if ($selected->fetch($pipelineId) > 0) {
			print '<h2>'.$langs->trans('CrmEditPipeline').'</h2>';
			if (!$selected->hasOpenStage()) {
				print '<div class="warning">'.$langs->trans('CrmPipelineNoOpenStage').'</div>';
			}
			print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="savepipeline">';
			print '<input type="hidden" name="pipelineid" value="'.((int) $selected->id).'">';
			print dol_escape_htmltag($selected->ref).' <input name="label" class="minwidth200" value="'.dol_escape_htmltag($selected->label).'" required> ';
			print '<label><input type="checkbox" name="active" value="1"'.($selected->active ? ' checked' : '').'> '.$langs->trans('CrmActive').'</label> ';
			print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
			print '</form>';
			print crmHelp($langs->trans('CrmEditPipelineHelp'));
		}
	}
	print '<h2>'.$langs->trans('CrmNewPipeline').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addpipeline">';
	print '<input name="ref" placeholder="'.dol_escape_htmltag($langs->trans('Ref')).'" required> ';
	print '<input name="label" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" required> ';
	print $langs->trans('CrmCopyStagesFrom').' <select name="copyfrom"><option value="0">'.$langs->trans('CrmCopyNothing').'</option>';
	foreach ($pipelines as $pipeline) {
		print '<option value="'.((int) $pipeline->id).'"'.($pipeline->id === $pipelineId ? ' selected' : '').'>'.dol_escape_htmltag(crmDictLabel((string) $pipeline->label)).'</option>';
	}
	print '</select> ';
	print '<input type="submit" class="button" value="'.$langs->trans('Create').'">';
	print '</form>';
	print crmHelp($langs->trans('CrmNewPipelineHelp'));
}

if ($tab === 'qualification') {
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmCode').'</td><td>'.$langs->trans('CrmLabel').'</td><td>'.$langs->trans('CrmPoints').'</td></tr>';
	foreach ((new Qualification($db))->fetchItems() as $item) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $item->code).'</td><td>'.dol_escape_htmltag(crmDictLabel((string) $item->label)).'</td><td>'.(int) $item->points.'</td></tr>';
	}
	print '</table>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?tab=qualification">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savequal">';
	print '<input name="code" placeholder="'.dol_escape_htmltag($langs->trans('CrmCode')).'" required> ';
	print '<input name="label" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" required> ';
	print '<input name="points" type="number" value="25" class="width50"> ';
	print '<input name="position" type="number" value="0" class="width50"> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmAdd').'">';
	print '</form>';
}

if ($tab === 'reasons' || $tab === 'sources') {
	$which = ($tab === 'reasons') ? 'loss_reason' : 'source';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmCode').'</td><td>'.$langs->trans('CrmLabel').'</td></tr>';
	foreach (crmFetchDictionary($db, $which, false) as $row) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $row->code).'</td><td>'.dol_escape_htmltag(crmDictLabel((string) $row->label)).'</td></tr>';
	}
	print '</table>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savedict">';
	print '<input type="hidden" name="which" value="'.$which.'">';
	print '<input name="code" placeholder="'.dol_escape_htmltag($langs->trans('CrmCode')).'" required> ';
	print '<input name="label" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" required> ';
	print '<input name="position" type="number" value="0" class="width50"> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmAdd').'">';
	print '</form>';
}

if ($tab === 'about') {
	print '<p>'.$langs->trans('CrmAboutText').'</p>';
	print '<p>'.$langs->trans('CrmPlaceholders').'</p>';
}

print dol_get_fiche_end();
crmHelpToggle();
llxFooter();
$db->close();
