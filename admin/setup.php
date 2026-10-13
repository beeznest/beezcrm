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
	'CRM_ARCHIVE_MAILBOX' => 'CrmArchiveMailbox',
	'CRM_ARCHIVE_COPY' => 'CrmArchiveCopy',
);

if ($action === 'setconst') {
	foreach ($constants as $name => $label) {
		dolibarr_set_const($db, $name, GETPOST($name, 'alphanohtml'), 'chaine', 0, '', (int) $conf->entity);
	}
	setEventMessages($langs->trans('RecordSaved'), null);
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=parameters');
	exit;
}

if ($action === 'addpipeline') {
	$pipeline = new Pipeline($db);
	$pipeline->ref = GETPOST('ref', 'aZ09');
	$pipeline->label = GETPOST('label', 'alphanohtml');
	$pipeline->active = 1;
	if ($pipeline->ref === '' || $pipeline->create($user) <= 0) {
		setEventMessages($pipeline->error !== '' ? $pipeline->error : $langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages');
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
			print '<br><span class="opacitymedium">'.$langs->trans('CrmSequenceEmailModeHelp').'</span></td></tr>';
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
	print '<p class="opacitymedium">'.$langs->trans('CrmRunNote').'</p>';
	print '<p class="opacitymedium">'.$langs->trans('CrmArchiveHelp').'</p>';
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
	print '<input name="rotting_days" type="number" value="15" class="width50"> ';
	print '<select name="forecast_category"><option>pipeline</option><option>upside</option><option>commit</option><option>closed</option><option>lost</option></select> ';
	print '<label><input type="checkbox" name="is_won" value="1"> '.$langs->trans('CrmWonStage').'</label> ';
	print '<label><input type="checkbox" name="is_lost" value="1"> '.$langs->trans('CrmLostStage').'</label> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmAdd').'">';
	print '</form>';
	print '<h2>'.$langs->trans('CrmPipeline').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addpipeline">';
	print '<input name="ref" placeholder="'.dol_escape_htmltag($langs->trans('Ref')).'" required> ';
	print '<input name="label" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" required> ';
	print '<input type="submit" class="button" value="'.$langs->trans('Create').'">';
	print '</form>';
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
llxFooter();
$db->close();
