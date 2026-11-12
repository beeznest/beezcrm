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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
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
crmRequireToken($action, array('askdelitem', 'askdelstage', 'edititem', 'editstage'));
$form = new Form($db);

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
	'CRM_REPLY_MOVES_DEAL' => 'CrmReplyMovesDeal',
	'CRM_AI_EMAIL_ASSIST' => 'CrmAiEmailAssist',
	'CRM_AI_EMAIL_PROMPT' => 'CrmAiEmailPrompt',
	'CRM_AI_TIMEOUT' => 'CrmAiTimeout',
	'CRM_AI_MAX_PER_HOUR' => 'CrmAiMaxPerHour',
	'CRM_ARCHIVE_MAILBOX' => 'CrmArchiveMailbox',
	'CRM_ARCHIVE_COPY' => 'CrmArchiveCopy',
	'CRM_ARCHIVE_REQUIRE_AUTH' => 'CrmArchiveRequireAuth',
	'CRM_OPTOUT_CONFIRM' => 'CrmOptOutConfirm',
	'CRM_CATEGORY_SINGLE' => 'CrmCategorySingle',
	'CRM_INTERNAL_REF_URL' => 'CrmInternalRefUrl',
	'CRM_MCP_ALLOW_DELETE' => 'CrmMcpAllowDelete',
);

// Settings that are only on or off are switches, saved at once. They are stored as 1 or 0, never deleted: a deleted
// constant would be recreated with its default the next time the module is enabled.
$switches = array('CRM_SEQUENCES_ON', 'CRM_DRY_RUN', 'CRM_REMINDERS_ON', 'CRM_REMINDER_DRY_RUN', 'CRM_REQUIRE_NEXT_STEP', 'CRM_REPLY_MOVES_DEAL', 'CRM_AI_EMAIL_ASSIST', 'CRM_ARCHIVE_REQUIRE_AUTH', 'CRM_OPTOUT_CONFIRM', 'CRM_MCP_ALLOW_DELETE');
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
	// Everything is checked before anything is written: one refused value saves nothing.
	$toSave = array();
	$refused = array();
	foreach ($constants as $name => $label) {
		if (in_array($name, $switches, true)) {
			continue;
		}
		$posted = ($name === 'CRM_CATEGORY_SINGLE') ? implode(',', array_map('intval', (array) GETPOST($name, 'array:int'))) : GETPOST($name, in_array($name, array('CRM_AI_EMAIL_PROMPT', 'CRM_INTERNAL_REF_URL'), true) ? 'nohtml' : 'alphanohtml');
		$clean = crmNormalizeSetting($name, $posted);
		if ($clean === null) {
			$refused[] = $langs->trans($label);
		} else {
			$toSave[$name] = $clean;
		}
	}
	if (count($refused) > 0) {
		setEventMessages($langs->trans('CrmSettingRefused', dol_escape_htmltag(implode(', ', $refused))), null, 'errors');
	} else {
		foreach ($toSave as $name => $clean) {
			dolibarr_set_const($db, $name, $clean, 'chaine', 0, '', (int) $conf->entity);
			dol_syslog('CRM setting '.$name.' changed by user '.((int) $user->id), LOG_INFO);
		}
		setEventMessages($langs->trans('RecordSaved'), null);
	}
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

// A list entry that is turned off leaves the choice lists but stays on the deals that already use it, which is why a used
// one is turned off rather than deleted.
$itemKinds = array('qualification' => 'qualification', 'loss_reason' => 'reasons', 'source' => 'sources');

if ($action === 'savestage') {
	$stage = new Stage($db);
	$stageId = GETPOSTINT('stageid');
	if ($stageId > 0) {
		$stage->fetch($stageId);
	}
	$stage->fk_pipeline = GETPOSTINT('pipelineid');
	$stage->label = trim(GETPOST('label', 'alphanohtml'));
	$stage->position = GETPOSTINT('position');
	$stage->color = GETPOST('color', 'alphanohtml');
	if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $stage->color)) {
		$stage->color = '#6c757d';
	}
	$stage->probability = max(0, min(100, GETPOSTINT('probability')));
	$stage->rotting_days = max(0, GETPOSTINT('rotting_days'));
	$stage->forecast_category = GETPOST('forecast_category', 'aZ09');
	if (!in_array($stage->forecast_category, array('pipeline', 'upside', 'commit', 'closed', 'lost'), true)) {
		$stage->forecast_category = 'pipeline';
	}
	$stage->is_won = GETPOSTINT('is_won') ? 1 : 0;
	$stage->is_lost = GETPOSTINT('is_lost') ? 1 : 0;
	$stage->is_reply = GETPOSTINT('is_reply') ? 1 : 0;
	$result = ($stageId > 0) ? $stage->update() : $stage->create();
	if ($result <= 0) {
		setEventMessages(crmErrorText($stage->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.(int) $stage->fk_pipeline);
	exit;
}

if ($action === 'togglestage') {
	$stage = new Stage($db);
	if ($stage->fetch(GETPOSTINT('stageid')) > 0) {
		$stage->active = GETPOSTINT('active') ? 1 : 0;
		if ($stage->update() <= 0) {
			setEventMessages(crmErrorText($stage->error), null, 'errors');
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.GETPOSTINT('pipelineid'));
	exit;
}

if ($action === 'confirm_delstage' && GETPOST('confirm', 'alpha') === 'yes') {
	$stage = new Stage($db);
	if ($stage->fetch(GETPOSTINT('stageid')) > 0 && $stage->delete() <= 0) {
		setEventMessages(crmErrorText($stage->error), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.GETPOSTINT('pipelineid'));
	exit;
}

if ($action === 'savequal') {
	$itemId = GETPOSTINT('itemid');
	$id = crmSaveQualificationItem($db, $itemId, GETPOST('code', 'aZ09'), trim(GETPOST('label', 'alphanohtml')), GETPOSTINT('points'), GETPOSTINT('position'), $itemId > 0 ? -1 : 1);
	if ($id <= 0) {
		setEventMessages(crmErrorText($id === -2 ? 'CrmErrorCodeExists' : 'Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab=qualification');
	exit;
}

if ($action === 'savedict') {
	$which = GETPOST('which', 'aZ09');
	if ($which !== 'source' && $which !== 'loss_reason') {
		$which = 'source';
	}
	$itemId = GETPOSTINT('itemid');
	$id = crmSaveDictionary($db, $which, $itemId, GETPOST('code', 'aZ09'), trim(GETPOST('label', 'alphanohtml')), GETPOSTINT('position'), $itemId > 0 ? -1 : 1);
	if ($id <= 0) {
		setEventMessages(crmErrorText($id === -2 ? 'CrmErrorCodeExists' : 'Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?tab='.$itemKinds[$which]);
	exit;
}

if ($action === 'toggleitem' || $action === 'confirm_delitem') {
	$kind = GETPOST('kind', 'aZ09');
	if (isset($itemKinds[$kind])) {
		if ($action === 'toggleitem') {
			crmSetItemActive($db, $kind, GETPOSTINT('itemid'), GETPOSTINT('active'));
		} elseif (GETPOST('confirm', 'alpha') === 'yes') {
			$error = crmDeleteItem($db, $kind, GETPOSTINT('itemid'));
			if ($error !== '') {
				setEventMessages(crmErrorText($error), null, 'errors');
			}
		}
		header('Location: '.$_SERVER['PHP_SELF'].'?tab='.$itemKinds[$kind]);
		exit;
	}
}

$head = array();
foreach (array('parameters' => 'CrmParameters', 'stages' => 'CrmStages', 'qualification' => 'CrmQualification', 'reasons' => 'CrmLossReasons', 'sources' => 'CrmSources', 'about' => 'CrmAbout') as $code => $key) {
	$head[] = array($_SERVER['PHP_SELF'].'?tab='.$code, $langs->trans($key), $code);
}

llxHeader('', $langs->trans('CrmSetup'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmSetup'), '', 'fa-handshake');
print crmSchemaWarning();
print dol_get_fiche_head($head, $tab, '', -1, 'fa-handshake');

if ($tab === 'parameters') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="setconst">';
	print '<input type="hidden" name="tab" value="parameters">';
	print '<table class="noborder centpercent">';
	foreach ($constants as $name => $label) {
		print '<tr class="oddeven"><td>'.$langs->trans($label);
		if ($name === 'CRM_CATEGORY_SINGLE' || $name === 'CRM_INTERNAL_REF_URL') {
			// A short line under the name, always visible (the longer explanation is behind the "?").
			print crmHelp($langs->trans($name === 'CRM_INTERNAL_REF_URL' ? 'CrmInternalRefUrlHint' : 'CrmCategorySingleHint'), 'div');
		}
		print '</td>';
		if ($name === 'CRM_SEQUENCE_EMAIL_MODE') {
			$mode = crmSequenceDraftMode() ? 'draft' : 'send';
			print '<td><select name="'.$name.'"><option value="draft"'.($mode === 'draft' ? ' selected' : '').'>'.$langs->trans('CrmSequenceEmailModeDraft').'</option>';
			print '<option value="send"'.($mode === 'send' ? ' selected' : '').'>'.$langs->trans('CrmSequenceEmailModeSend').'</option></select>';
			print crmHelp($langs->trans('CrmSequenceEmailModeHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if ($name === 'CRM_CATEGORY_SINGLE') {
			// From v1.2.0: groups of categories of third parties whose sub-categories exclude each other (a degree of interest).
			// A list of check boxes, not a multiple <select>: Dolibarr turns a select into a widget that the mouse wheel cannot scroll.
			require_once __DIR__.'/../class/crmcategory.class.php';
			$categoryModel = new CrmCategory($db);
			$chosen = crmIdList(getDolGlobalString($name));
			print '<td>';
			if (!CrmCategory::enabled()) {
				print '<span class="opacitymedium">'.$langs->trans('CrmCategoryModuleOff').'</span>';
			} else {
				$groupCandidates = $categoryModel->fetchAvailable();
				$hasChildren = array();
				foreach ($groupCandidates as $candidate) {
					if ($candidate['fk_parent'] > 0) {
						$hasChildren[$candidate['fk_parent']] = true;
					}
				}
				print '<div class="crm-checklist">';
				$shownGroups = 0;
				foreach ($groupCandidates as $candidate) {
					// Only a category that has sub-categories can be a group (and one already chosen, so it can be unticked).
					if (empty($hasChildren[$candidate['id']]) && !in_array($candidate['id'], $chosen, true)) {
						continue;
					}
					$shownGroups++;
					print '<label><input type="checkbox" name="'.$name.'[]" value="'.$candidate['id'].'"'.(in_array($candidate['id'], $chosen, true) ? ' checked' : '').'> '.dol_escape_htmltag(($candidate['parent_label'] !== '' ? $candidate['parent_label'].' >> ' : '').$candidate['label']).'</label>';
				}
				if ($shownGroups === 0) {
					print '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CrmCategorySingleNone')).'</span>';
				}
				print '</div>';
			}
			print crmHelp($langs->trans('CrmCategorySingleHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if ($name === 'CRM_AI_EMAIL_PROMPT') {
			print '<td><textarea class="quatrevingtpercent" name="'.$name.'" rows="5">'.dol_escape_htmltag(getDolGlobalString($name), 0, 1).'</textarea>';
			print crmHelp($langs->trans('CrmAiEmailPromptHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if (in_array($name, $switches, true)) {
			// A switch that is on until someone turns it off shows as on before the module has been enabled again to create it.
			$on = getDolGlobalInt($name, in_array($name, array('CRM_REPLY_MOVES_DEAL', 'CRM_OPTOUT_CONFIRM'), true) ? 1 : 0) !== 0;
			// A confirmation before the two switches that have a consequence outside the module.
			$confirmOn = ($name === 'CRM_AI_EMAIL_ASSIST') ? $langs->trans('CrmConfirmAiOn') : (($name === 'CRM_MCP_ALLOW_DELETE') ? $langs->trans('CrmConfirmMcpAllowDeleteOn') : '');
			$confirmOff = ($name === 'CRM_DRY_RUN') ? $langs->trans('CrmConfirmDryRunOff') : (($name === 'CRM_REMINDER_DRY_RUN') ? $langs->trans('CrmConfirmReminderDryRunOff') : '');
			print '<td><a class="crm-toggle'.($on ? ' crm-toggle-on' : '').'" data-name="'.$name.'" data-value="'.($on ? 0 : 1).'"';
			print ' data-confirm-on="'.dol_escape_htmltag($confirmOn).'" data-confirm-off="'.dol_escape_htmltag($confirmOff).'"';
			print ' href="'.$_SERVER['PHP_SELF'].'?tab=parameters&action='.($on ? 'del_' : 'set_').$name.'&token='.newToken().'">';
			print '<span class="crm-sw-on">'.img_picto($langs->trans('Enabled'), 'switch_on').'</span><span class="crm-sw-off">'.img_picto($langs->trans('Disabled'), 'switch_off').'</span></a>';
			if ($name === 'CRM_AI_EMAIL_ASSIST') {
				print crmHelp($langs->trans('CrmAiEmailAssistHelp'), 'span', true);
			}
			if ($name === 'CRM_REPLY_MOVES_DEAL') {
				print crmHelp($langs->trans('CrmReplyMovesDealHelp'), 'span', true);
			}
			if ($name === 'CRM_ARCHIVE_REQUIRE_AUTH') {
				print crmHelp($langs->trans('CrmArchiveRequireAuthHelp'), 'span', true);
			}
			if ($name === 'CRM_OPTOUT_CONFIRM') {
				print crmHelp($langs->trans('CrmOptOutConfirmHelp'), 'span', true);
			}
			if ($name === 'CRM_MCP_ALLOW_DELETE') {
				print crmHelp($langs->trans('CrmMcpAllowDeleteHelp'), 'span', true);
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
		if ($name === 'CRM_AI_TIMEOUT') {
			print '<td><input type="number" min="10" max="600" class="width75" name="'.$name.'" value="'.dol_escape_htmltag(getDolGlobalString($name, '120')).'">';
			print crmHelp($langs->trans('CrmAiTimeoutHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if ($name === 'CRM_AI_MAX_PER_HOUR') {
			print '<td><input type="number" min="1" max="1000" class="width75" name="'.$name.'" value="'.dol_escape_htmltag(getDolGlobalString($name, '30')).'">';
			print crmHelp($langs->trans('CrmAiMaxPerHourHelp'), 'span', true).'</td></tr>';
			continue;
		}
		if ($name === 'CRM_INTERNAL_REF_URL') {
			print '<td><input class="minwidth400" type="url" name="'.$name.'" maxlength="500" placeholder="https://tracker.example.com/browse/" value="'.dol_escape_htmltag(getDolGlobalString($name)).'">';
			print crmHelp($langs->trans('CrmInternalRefUrlHelp'), 'span', true).'</td></tr>';
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
	$stageUrl = $_SERVER['PHP_SELF'].'?tab=stages&pipelineid='.$pipelineId;
	if ($action === 'askdelstage') {
		$askStage = new Stage($db);
		if ($askStage->fetch(GETPOSTINT('stageid')) > 0) {
			print $form->formconfirm($stageUrl.'&stageid='.(int) $askStage->id, $langs->trans('CrmDeleteStage'), $langs->trans('CrmConfirmDeleteStage', dol_escape_htmltag(crmStageLabel((string) $askStage->label))), 'confirm_delstage', '', 0, 1);
		}
	}
	// The fields of a stage, to add one or to change one.
	$stageFields = function (?Stage $one) use ($langs, $conf) {
		$out = '<input name="label" class="minwidth150" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" value="'.dol_escape_htmltag($one ? $one->label : '').'" required> ';
		$out .= '<input name="position" type="number" title="'.dol_escape_htmltag($langs->trans('CrmPosition')).'" value="'.($one ? (int) $one->position : 0).'" class="width50"> ';
		$out .= '<input name="color" title="'.dol_escape_htmltag($langs->trans('CrmColor')).'" value="'.dol_escape_htmltag($one ? $one->color : '#6c757d').'" class="width75"> ';
		$out .= '<input name="probability" type="number" min="0" max="100" title="'.dol_escape_htmltag($langs->trans('CrmProbability')).'" value="'.($one ? (int) $one->probability : 10).'" class="width50">% ';
		$out .= '<input name="rotting_days" type="number" min="0" title="'.dol_escape_htmltag($langs->trans('CrmRottingDays')).'" value="'.($one ? (int) $one->rotting_days : max(0, getDolGlobalInt('CRM_ROTTING_DAYS', 15))).'" class="width50"> ';
		$out .= '<select name="forecast_category">';
		foreach (array('pipeline', 'upside', 'commit', 'closed', 'lost') as $category) {
			$out .= '<option'.(($one && $one->forecast_category === $category) ? ' selected' : '').'>'.$category.'</option>';
		}
		$out .= '</select> ';
		$out .= '<label><input type="checkbox" name="is_won" value="1"'.(($one && $one->is_won) ? ' checked' : '').'> '.$langs->trans('CrmWonStage').'</label> ';
		$out .= '<label><input type="checkbox" name="is_lost" value="1"'.(($one && $one->is_lost) ? ' checked' : '').'> '.$langs->trans('CrmLostStage').'</label> ';
		$out .= '<label title="'.dol_escape_htmltag($langs->trans('CrmReplyStageTip')).'"><input type="checkbox" name="is_reply" value="1"'.(($one && $one->is_reply) ? ' checked' : '').'> '.$langs->trans('CrmReplyStage').'</label> ';
		return $out;
	};
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmLabel').'</td><td>'.$langs->trans('CrmPosition').'</td><td>'.$langs->trans('CrmProbability').'</td><td>'.$langs->trans('CrmForecastCategory').'</td><td></td></tr>';
	if ($pipelineId > 0) {
		foreach ((new Stage($db))->fetchAll($pipelineId) as $stage) {
			if ($action === 'editstage' && GETPOSTINT('stageid') === (int) $stage->id) {
				print '<tr class="oddeven"><td colspan="5"><form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
				print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savestage">';
				print '<input type="hidden" name="pipelineid" value="'.((int) $pipelineId).'"><input type="hidden" name="stageid" value="'.((int) $stage->id).'">';
				print $stageFields($stage);
				print '<input type="submit" class="button" value="'.$langs->trans('Save').'"> <a class="button button-cancel" href="'.dol_escape_htmltag($stageUrl).'">'.$langs->trans('Cancel').'</a>';
				print '</form></td></tr>';
				continue;
			}
			$flags = ($stage->is_won ? ' · '.$langs->trans('CrmWonStage') : '').($stage->is_lost ? ' · '.$langs->trans('CrmLostStage') : '').($stage->is_reply ? ' · '.$langs->trans('CrmReplyStage') : '');
			print '<tr class="oddeven'.($stage->active ? '' : ' opacitymedium').'"><td><span class="crm-swatch" style="background:'.dol_escape_htmltag($stage->color).'"></span> '.dol_escape_htmltag(crmStageLabel((string) $stage->label));
			if (!$stage->active) {
				print ' <span class="badge badge-status8">'.$langs->trans('CrmInactive').'</span>';
			}
			print '</td><td>'.(int) $stage->position.'</td><td>'.(int) $stage->probability.'%</td><td>'.dol_escape_htmltag($stage->forecast_category).$flags.'</td>';
			print '<td class="right nowraponall">';
			print crmIconLink($stageUrl.'&action=editstage&stageid='.(int) $stage->id.'&token='.newToken(), 'edit', $langs->trans('Modify'));
			print crmIconLink($stageUrl.'&action=togglestage&stageid='.(int) $stage->id.'&active='.($stage->active ? 0 : 1).'&token='.newToken(), $stage->active ? 'switch_on' : 'switch_off', $langs->trans($stage->active ? 'CrmDeactivate' : 'CrmActivate'));
			print crmIconLink($stageUrl.'&action=askdelstage&stageid='.(int) $stage->id.'&token='.newToken(), 'delete', $langs->trans('CrmDeleteStage'));
			print '</td></tr>';
		}
	}
	print '</table>';
	print crmHelp($langs->trans('CrmStageEditHelp'));
	print '<h2>'.$langs->trans('CrmAdd').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savestage">';
	print '<input type="hidden" name="pipelineid" value="'.((int) $pipelineId).'">';
	print $stageFields(null);
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

if ($tab === 'qualification' || $tab === 'reasons' || $tab === 'sources') {
	$kind = ($tab === 'qualification') ? 'qualification' : (($tab === 'reasons') ? 'loss_reason' : 'source');
	$withPoints = ($kind === 'qualification');
	$listUrl = $_SERVER['PHP_SELF'].'?tab='.$tab;
	$rows = $withPoints ? (new Qualification($db))->fetchItems(false) : crmFetchDictionary($db, $kind, false);
	if ($action === 'askdelitem') {
		foreach ($rows as $row) {
			if ((int) $row->rowid === GETPOSTINT('itemid')) {
				print $form->formconfirm($listUrl.'&kind='.$kind.'&itemid='.(int) $row->rowid, $langs->trans('Delete'), $langs->trans('CrmConfirmDeleteItem', dol_escape_htmltag(crmDictLabel((string) $row->label))), 'confirm_delitem', '', 0, 1);
			}
		}
	}
	// The fields of an entry, to add one or to change one.
	$itemFields = function ($row) use ($langs, $withPoints) {
		$out = '<input name="code" class="width100" placeholder="'.dol_escape_htmltag($langs->trans('CrmCode')).'" value="'.dol_escape_htmltag($row ? (string) $row->code : '').'" required> ';
		$out .= '<input name="label" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'" value="'.dol_escape_htmltag($row ? (string) $row->label : '').'" required> ';
		if ($withPoints) {
			$out .= '<input name="points" type="number" title="'.dol_escape_htmltag($langs->trans('CrmPoints')).'" value="'.($row ? (int) $row->points : 25).'" class="width50"> ';
		}
		$out .= '<input name="position" type="number" title="'.dol_escape_htmltag($langs->trans('CrmPosition')).'" value="'.($row ? (int) $row->position : 0).'" class="width50"> ';
		return $out;
	};
	$formOpen = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="'.($withPoints ? 'savequal' : 'savedict').'">'.($withPoints ? '<input type="hidden" name="tab" value="qualification">' : '<input type="hidden" name="which" value="'.$kind.'">');
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmCode').'</td><td>'.$langs->trans('CrmLabel').'</td>'.($withPoints ? '<td>'.$langs->trans('CrmPoints').'</td>' : '').'<td>'.$langs->trans('CrmPosition').'</td><td>'.$langs->trans('CrmUsedBy').'</td><td></td></tr>';
	foreach ($rows as $row) {
		if ($action === 'edititem' && GETPOSTINT('itemid') === (int) $row->rowid) {
			print '<tr class="oddeven"><td colspan="'.($withPoints ? 6 : 5).'">'.$formOpen.'<input type="hidden" name="itemid" value="'.((int) $row->rowid).'">'.$itemFields($row);
			print '<input type="submit" class="button" value="'.$langs->trans('Save').'"> <a class="button button-cancel" href="'.dol_escape_htmltag($listUrl).'">'.$langs->trans('Cancel').'</a></form></td></tr>';
			continue;
		}
		$used = crmItemUsage($db, $kind, (int) $row->rowid);
		print '<tr class="oddeven'.($row->active ? '' : ' opacitymedium').'"><td>'.dol_escape_htmltag((string) $row->code).'</td><td>'.dol_escape_htmltag(crmDictLabel((string) $row->label));
		if (!$row->active) {
			print ' <span class="badge badge-status8">'.$langs->trans('CrmInactive').'</span>';
		}
		print '</td>'.($withPoints ? '<td>'.(int) $row->points.'</td>' : '').'<td>'.(int) $row->position.'</td><td>'.$used.'</td><td class="right nowraponall">';
		print crmIconLink($listUrl.'&action=edititem&itemid='.(int) $row->rowid.'&token='.newToken(), 'edit', $langs->trans('Modify'));
		print crmIconLink($listUrl.'&action=toggleitem&kind='.$kind.'&itemid='.(int) $row->rowid.'&active='.($row->active ? 0 : 1).'&token='.newToken(), $row->active ? 'switch_on' : 'switch_off', $langs->trans($row->active ? 'CrmDeactivate' : 'CrmActivate'));
		if ($used === 0) {
			print crmIconLink($listUrl.'&action=askdelitem&itemid='.(int) $row->rowid.'&token='.newToken(), 'delete', $langs->trans('Delete'));
		}
		print '</td></tr>';
	}
	print '</table>';
	print crmHelp($langs->trans($withPoints ? 'CrmQualificationEditHelp' : 'CrmDictionaryEditHelp'));
	print '<h2>'.$langs->trans('CrmAdd').'</h2>';
	print $formOpen.$itemFields(null).'<input type="submit" class="button" value="'.$langs->trans('CrmAdd').'"></form>';
}

if ($tab === 'about') {
	print '<p>'.$langs->trans('CrmAboutText').'</p>';
	require_once __DIR__.'/../core/modules/modCrm.class.php';
	$descriptor = new modCrm($db);
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('CrmAboutVersion').'</td><td>'.dol_escape_htmltag((string) $descriptor->version).'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmAboutSchema').'</td><td>'.crmSchemaInstalled().' / '.crmSchemaCurrent().' '.(crmSchemaPending() ? '<span class="badge badge-status8">'.$langs->trans('CrmAboutSchemaPending').'</span>' : '<span class="badge badge-status4">'.$langs->trans('CrmAboutSchemaOk').'</span>').'</td></tr>';
	print '</table><br>';
	print '<p>'.$langs->trans('CrmPlaceholders').'</p>';
}

print dol_get_fiche_end();
crmHelpToggle();
llxFooter();
$db->close();
