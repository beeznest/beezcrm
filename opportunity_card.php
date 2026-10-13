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
 * \file    opportunity_card.php
 * \ingroup crm
 * \brief   Deal card: fields, qualification, next step, proposals, win and loss.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/opportunity.class.php';
require_once __DIR__.'/class/pipeline.class.php';
require_once __DIR__.'/class/stage.class.php';
require_once __DIR__.'/class/qualification.class.php';
require_once __DIR__.'/class/crmactivity.class.php';
crmStartPage();

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$form = new Form($db);
$object = new Opportunity($db);
if ($id > 0 && $object->fetch($id) > 0 && !crmCanReadOwner($user, (int) $object->fk_user_resp)) {
	accessforbidden();
}
if ($id > 0 && $object->id <= 0 && $action !== 'create' && $action !== 'add') {
	accessforbidden();
}

/**
 * Copy the posted fields onto the deal. The stage is not one of them.
 */
$fill = function (Opportunity $deal) use ($user): void {
	$deal->label = GETPOST('label', 'alphanohtml');
	$deal->fk_soc = GETPOSTINT('socid');
	$deal->fk_socpeople = GETPOSTINT('contactid');
	$deal->fk_pipeline = GETPOSTINT('pipelineid');
	$deal->amount = (float) price2num(GETPOST('amount', 'alphanohtml'));
	$expected = GETPOSTDATE('dateclose');
	$deal->date_close_expected = is_numeric($expected) ? (int) $expected : 0;
	$deal->fk_source = GETPOSTINT('sourceid');
	$owner = GETPOSTINT('userid');
	if ($owner <= 0 || !$user->hasRight('crm', 'readall')) {
		$owner = (int) $user->id;
	}
	$deal->fk_user_resp = $owner;
	$deal->description = GETPOST('description', 'restricthtml');
};

$back = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.$id;

if ($action === 'add') {
	crmRequireRight('write');
	$fill($object);
	$newId = $object->create($user);
	if ($newId <= 0) {
		setEventMessages(crmErrorText($object->error), null, 'errors');
		$action = 'create';
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
		header('Location: '.dol_buildpath('/crm/opportunity_card.php', 1).'?id='.$newId);
		exit;
	}
}

if ($action === 'update' && $object->id > 0) {
	crmRequireRight('write');
	$fill($object);
	if ($object->update($user) <= 0) {
		setEventMessages(crmErrorText($object->error), null, 'errors');
		$action = 'edit';
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
		header('Location: '.$back);
		exit;
	}
}

if ($action === 'move' && $object->id > 0) {
	crmRequireRight('write');
	$result = $object->moveToStage($user, GETPOSTINT('stageid'), GETPOSTINT('lossreasonid'), GETPOST('lossnote', 'alphanohtml'));
	if ($result <= 0) {
		setEventMessages(crmErrorText($object->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
		header('Location: '.$back);
		exit;
	}
}

if ($action === 'qualify' && $object->id > 0) {
	crmRequireRight('write');
	$answers = array();
	foreach ((new Qualification($db))->fetchItems() as $item) {
		$answers[(int) $item->rowid] = GETPOSTINT('qual_'.$item->rowid) ? 1 : 0;
	}
	$qualSave = new Qualification($db);
	if ($qualSave->saveAnswers($user, (int) $object->id, $answers) <= 0) {
		setEventMessages($qualSave->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'addactivity' && $object->id > 0) {
	crmRequireRight('activity');
	$when = GETPOSTDATE('actdate');
	$datep = is_numeric($when) ? (int) $when : 0;
	$activity = new CrmActivity($db);
	$result = $activity->create($user, (int) $object->id, (int) $object->fk_soc, (int) $object->fk_socpeople, (int) $object->fk_user_resp, GETPOST('kind', 'aZ09'), GETPOST('actlabel', 'alphanohtml'), $datep, 0);
	if ($result <= 0) {
		setEventMessages($activity->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'complete' && $object->id > 0) {
	crmRequireRight('activity');
	$activity = new CrmActivity($db);
	if ($activity->complete($user, GETPOSTINT('eventid')) <= 0) {
		setEventMessages($activity->error, null, 'errors');
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'linkpropal' && $object->id > 0) {
	crmRequireRight('write');
	if ($object->linkProposal(GETPOSTINT('propalid')) <= 0) {
		setEventMessages($object->error, null, 'errors');
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'unlinkpropal' && $object->id > 0) {
	crmRequireRight('write');
	$object->unlinkProposal(GETPOSTINT('propalid'));
	header('Location: '.$back);
	exit;
}

if ($action === 'confirm_delete' && GETPOST('confirm', 'alpha') === 'yes' && $object->id > 0) {
	crmRequireRight('delete');
	if ($object->delete($user) > 0) {
		header('Location: '.dol_buildpath('/crm/opportunity_list.php', 1));
		exit;
	}
	setEventMessages($object->error, null, 'errors');
}

$pipelines = (new Pipeline($db))->fetchAll(true);
$sources = crmFetchDictionary($db, 'source', true);
$reasons = crmFetchDictionary($db, 'loss_reason', true);
$stages = ($object->fk_pipeline > 0) ? (new Stage($db))->fetchAll((int) $object->fk_pipeline) : array();

llxHeader('', $langs->trans('CrmOpportunity'), '', '', 0, 0, '', array('/crm/css/crm.css'));

if ($action === 'delete' && $object->id > 0) {
	print $form->formconfirm($back, $langs->trans('Delete'), $langs->trans('CrmConfirmDelete'), 'confirm_delete', '', 0, 1);
}

// Back from the third party creation form: socid is the new prospect, the other fields were carried along.
if ($action === 'create' && $object->id <= 0 && (GETPOSTISSET('socid') || GETPOSTISSET('label'))) {
	$fill($object);
}

if ($action === 'create' && !GETPOSTISSET('datecloseyear') && (int) $object->date_close_expected <= 0) {
	$todayParts = dol_getdate(dol_now());
	$object->date_close_expected = (int) dol_mktime(0, 0, 0, (int) $todayParts['mon'] + 1, (int) $todayParts['mday'], (int) $todayParts['year']);
}

if ($action === 'create' || $action === 'edit') {
	$creating = ($action === 'create');
	print load_fiche_titre($creating ? $langs->trans('CrmNewOpportunity') : $object->ref, '', 'fa-handshake');
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="'.($creating ? 'add' : 'update').'">';
	if (!$creating) {
		print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	}
	print '<table class="border centpercent">';
	print '<tr><td class="titlefield">'.$langs->trans('CrmTitle').'</td><td><input class="minwidth300" name="label" value="'.dol_escape_htmltag((string) $object->label).'" required></td></tr>';
	print '<tr><td>'.$langs->trans('CrmPipeline').'</td><td><select name="pipelineid" required>';
	foreach ($pipelines as $pipeline) {
		$selected = ((int) $object->fk_pipeline === (int) $pipeline->id) ? ' selected' : '';
		print '<option value="'.((int) $pipeline->id).'"'.$selected.'>'.dol_escape_htmltag(crmDictLabel((string) $pipeline->label)).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans('CrmAccount').'</td><td>';
if ($user->hasRight('societe', 'lire')) {
	// Searchable list of the existing third parties; showtype appends Prospect / Customer. Core restricts it to the caller's own accounts without the "all" right.
	print $form->select_company((int) $object->fk_soc, 'socid', '', 1, 1, 0, array(), 0, 'minwidth300 maxwidth500');
} else {
	print '<span class="opacitymedium">'.$langs->trans('CrmNoThirdPartyRight').'</span>';
	print '<input type="hidden" name="socid" value="'.((int) $object->fk_soc).'">';
}
if ($creating && $user->hasRight('societe', 'creer')) {
	// Same flow as the other core creation forms: the third party card, then back here with socid.
	$prospectUrl = DOL_URL_ROOT.'/societe/card.php?action=create&prospect=1&customer=0&fournisseur=0';
	$backToCreate = dol_buildpath('/crm/opportunity_card.php', 1).'?action=create';
	print ' <a id="crm-new-prospect" class="butActionNew" data-url="'.dol_escape_htmltag($prospectUrl).'" href="'.dol_escape_htmltag($prospectUrl.'&backtopage='.urlencode($backToCreate)).'">';
	print '<span class="fa fa-plus-circle valignmiddle paddingright"></span>'.$langs->trans('CrmNewProspect').'</a>';
	// Keep what was already typed: it travels in the return URL and is restored on return.
	print '<script>
	jQuery(function () {
		jQuery("#crm-new-prospect").on("click", function (event) {
			var form = jQuery(this).closest("form");
			var back = ' . json_encode($backToCreate) . ';
			jQuery.each(["label", "pipelineid", "amount", "sourceid", "userid", "description"], function (i, name) {
				var field = form.find("[name=" + name + "]");
				if (field.length) {
					back += "&" + name + "=" + encodeURIComponent(field.val());
				}
			});
			if (encodeURIComponent(back).length > 1800) {
				back = ' . json_encode($backToCreate) . ';
			}
			event.preventDefault();
			window.location.href = jQuery(this).data("url") + "&backtopage=" + encodeURIComponent(back);
		});
	});
	</script>';
}
	print '<br><span class="opacitymedium">'.$langs->trans('CrmAccountHelp').'</span></td></tr>';
	print '<tr><td>'.$langs->trans('CrmContact').'</td><td>'.$form->selectcontacts((int) $object->fk_soc, (int) $object->fk_socpeople, 'contactid', 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmAmount').'</td><td><input name="amount" value="'.price((float) $object->amount).'"></td></tr>';
	print '<tr><td>'.$langs->trans('CrmExpectedClose').'</td><td>'.$form->selectDate((int) $object->date_close_expected, 'dateclose', 0, 0, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmSource').'</td><td><select name="sourceid"><option value="0"></option>';
	foreach ($sources as $source) {
		$selected = ((int) $object->fk_source === (int) $source->rowid) ? ' selected' : '';
		print '<option value="'.((int) $source->rowid).'"'.$selected.'>'.dol_escape_htmltag(crmDictLabel((string) $source->label)).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans('CrmOwner').'</td><td>';
	if ($user->hasRight('crm', 'readall')) {
		print $form->select_dolusers((int) ($object->fk_user_resp ?: $user->id), 'userid', 0, null, 0, '', '', (string) $conf->entity);
	} else {
		$ownerName = trim((string) $user->firstname.' '.(string) $user->lastname);
		if ($ownerName === '') {
			$ownerName = (string) $user->login;
		}
		print dol_escape_htmltag($ownerName);
		print '<input type="hidden" name="userid" value="'.((int) $user->id).'">';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('CrmDescription').'</td><td><textarea name="description" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag((string) $object->description, 0, 1).'</textarea></td></tr>';
	print '</table>';
	print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
	llxFooter();
	$db->close();
	return;
}

print load_fiche_titre($object->ref.' — '.$object->label, '', 'fa-handshake');
print '<div class="tabsAction">';
if ($user->hasRight('crm', 'write')) {
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=edit&token='.newToken().'">'.$langs->trans('Modify').'</a>';
}
if ($user->hasRight('crm', 'delete')) {
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=delete&token='.newToken().'">'.$langs->trans('Delete').'</a>';
}
print '</div>';

$crmEmpty = '<span class="opacitymedium">'.$langs->trans('None').'</span>';
$stageColor = function ($color): string {
	$color = (string) $color;
	return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : '#6c757d';
};
$currentStage = null;
foreach ($stages as $stage) {
	if ((int) $stage->id === (int) $object->fk_stage) {
		$currentStage = $stage;
		break;
	}
}
$pipelineLabel = '';
foreach ($pipelines as $pipeline) {
	if ((int) $pipeline->id === (int) $object->fk_pipeline) {
		$pipelineLabel = crmDictLabel((string) $pipeline->label);
		break;
	}
}
$sourceLabel = '';
foreach ($sources as $source) {
	if ((int) $source->rowid === (int) $object->fk_source) {
		$sourceLabel = crmDictLabel((string) $source->label);
		break;
	}
}
$statusCode = (string) $object->status;
if ($statusCode === 'won') {
	$statusLabel = $langs->trans('CrmWon');
} elseif ($statusCode === 'lost') {
	$statusLabel = $langs->trans('CrmLost');
} else {
	$statusCode = 'open';
	$statusLabel = $langs->trans('CrmOpen');
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('CrmStatus').'</td><td><span class="crm-status crm-status-'.$statusCode.'">'.dol_escape_htmltag($statusLabel).'</span></td></tr>';
print '<tr><td>'.$langs->trans('CrmStage').'</td><td>';
if ($currentStage) {
	print '<span class="crm-stage-current" style="--crm-stage:'.$stageColor($currentStage->color).'">'.dol_escape_htmltag(crmStageLabel((string) $currentStage->label)).'</span>';
	print ' <span class="opacitymedium">'.((int) $currentStage->probability).'%</span>';
} else {
	print $crmEmpty;
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('CrmPipeline').'</td><td>'.($pipelineLabel !== '' ? dol_escape_htmltag($pipelineLabel) : $crmEmpty).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmAmount').'</td><td>'.price((float) $object->amount).'</td></tr>';
$expectedClose = (int) $object->date_close_expected;
print '<tr><td>'.$langs->trans('CrmExpectedClose').'</td><td>'.($expectedClose > 0 ? dol_print_date($expectedClose, 'day') : $crmEmpty).'</td></tr>';
print '</table>';
print '</div>';
print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans('CrmAccount').'</td><td>';
if ($object->fk_soc > 0) {
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
	$societe = new Societe($db);
	if ($societe->fetch((int) $object->fk_soc) > 0) {
		print $societe->getNomUrl(1);
	} else {
		print $crmEmpty;
	}
} else {
	print $crmEmpty;
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('CrmContact').'</td><td>';
if ($object->fk_socpeople > 0) {
	require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
	$contact = new Contact($db);
	if ($contact->fetch((int) $object->fk_socpeople) > 0) {
		print $contact->getNomUrl(1);
	} else {
		print $crmEmpty;
	}
} else {
	print $crmEmpty;
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('CrmOwner').'</td><td>';
$ownerUser = new User($db);
if ((int) $object->fk_user_resp > 0 && $ownerUser->fetch((int) $object->fk_user_resp) > 0) {
	print $ownerUser->getNomUrl(1);
} else {
	print $crmEmpty;
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('CrmSource').'</td><td>'.($sourceLabel !== '' ? dol_escape_htmltag($sourceLabel) : $crmEmpty).'</td></tr>';
print '</table>';
print '</div>';
print '</div>';
print '<div class="clearboth"></div>';
if (trim((string) $object->description) !== '') {
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('CrmDescription').'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->description, 0, 1)).'</td></tr>';
	print '</table>';
}

if ($user->hasRight('crm', 'write')) {
	print '<h2>'.$langs->trans('CrmStage').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="move">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	$lostSelected = ($currentStage && (int) $currentStage->is_lost === 1);
	print '<select name="stageid" id="crm-stageid">';
	foreach ($stages as $stage) {
		$selected = ((int) $object->fk_stage === (int) $stage->id) ? ' selected' : '';
		print '<option value="'.((int) $stage->id).'" data-lost="'.((int) $stage->is_lost).'"'.$selected.'>'.dol_escape_htmltag(crmStageLabel((string) $stage->label)).'</option>';
	}
	print '</select> ';
	print '<span id="crm-loss-reason"'.($lostSelected ? '' : ' style="display:none"').'>';
	print $langs->trans('CrmLossReason').' <select name="lossreasonid" id="crm-lossreason"'.($lostSelected ? '' : ' disabled').'><option value="0"></option>';
	foreach ($reasons as $reason) {
		print '<option value="'.((int) $reason->rowid).'">'.dol_escape_htmltag(crmDictLabel((string) $reason->label)).'</option>';
	}
	print '</select></span> ';
	print '<script nonce="'.dol_escape_htmltag(getNonce()).'">';
	print '(function () {';
	print 'var stage = document.getElementById("crm-stageid");';
	print 'var box = document.getElementById("crm-loss-reason");';
	print 'var reason = document.getElementById("crm-lossreason");';
	print 'if (!stage || !box || !reason) { return; }';
	print 'function sync() {';
	print 'var option = stage.options[stage.selectedIndex];';
	print 'var lost = option && option.getAttribute("data-lost") === "1";';
	print 'box.style.display = lost ? "" : "none";';
	print 'reason.disabled = !lost;';
	print '}';
	print 'stage.addEventListener("change", sync);';
	print 'sync();';
	print '}());';
	print '</script>';
	print '<input name="lossnote" placeholder="'.dol_escape_htmltag($langs->trans('CrmLossNote')).'" value="'.dol_escape_htmltag((string) $object->loss_note).'"> ';
	print '<input type="submit" class="button" value="'.$langs->trans('CrmMove').'">';
	if (getDolGlobalInt('CRM_REQUIRE_NEXT_STEP')) {
		print '<br><span class="opacitymedium">'.$langs->trans('CrmMoveNextStepHelp').'</span>';
	}
	print '</form>';
}

$qual = new Qualification($db);
$items = $qual->fetchForOpportunity((int) $object->id);
$score = $qual->score($items);
$scoreWidth = max(0, min(100, (int) $score));
print '<div class="crm-section">';
print '<div class="crm-section-head"><span>'.$langs->trans('CrmQualification').'</span>';
print '<span class="crm-score-row"><span class="crm-scorebar"><span style="width:'.$scoreWidth.'%"></span></span> '.$scoreWidth.'</span></div>';
if (count($qual->missing($items)) > 0) {
	print '<p class="opacitymedium crm-qual-missing">'.$langs->trans('CrmMissing').': '.dol_escape_htmltag(implode(', ', array_map('crmDictLabel', $qual->missing($items)))).'</p>';
}
if ($user->hasRight('crm', 'write')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="qualify">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
}
print '<div class="crm-qual-grid">';
foreach ($items as $item) {
	$checked = !empty($item['answer']) ? ' checked' : '';
	print '<label><input type="checkbox" name="qual_'.((int) $item['id']).'" value="1"'.$checked.'> '.dol_escape_htmltag(crmDictLabel((string) $item['label']));
	print ' <span class="crm-qual-points">'.((int) $item['points']).'</span></label>';
}
print '</div>';
if ($user->hasRight('crm', 'write')) {
	print '<input type="submit" class="button" value="'.$langs->trans('Save').'">';
	print '</form>';
}
print '</div>';

print '<h2>'.$langs->trans('CrmNextStep').'</h2>';
if ($user->hasRight('crm', 'activity')) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="addactivity">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	print '<select name="kind">';
	foreach (array('call' => 'CrmCall', 'meeting' => 'CrmMeeting', 'task' => 'CrmTask', 'email' => 'CrmEmail') as $kind => $key) {
		$selected = ($kind === 'email') ? ' selected' : '';
		print '<option value="'.$kind.'"'.$selected.'>'.$langs->trans($key).'</option>';
	}
	print '</select> ';
	print '<input name="actlabel" class="minwidth200" required placeholder="'.dol_escape_htmltag($langs->trans('CrmTitle')).'"> ';
	print $form->selectDate(dol_now() + 86400, 'actdate', 0, 0, 0);
	print ' <input type="submit" class="button" value="'.$langs->trans('Create').'">';
	print '</form>';
}
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmTitle').'</td><td>'.$langs->trans('CrmKind').'</td><td>'.$langs->trans('CrmStatus').'</td><td></td></tr>';
$events = (new CrmActivity($db))->fetchForOpportunity((int) $object->id);
if (count($events) === 0) {
	print '<tr class="oddeven"><td colspan="5">'.$langs->trans('CrmNoRows').'</td></tr>';
}
foreach ($events as $event) {
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($event->datep), 'dayhour').'</td>';
	$isDraftEmail = CrmActivity::isPlannedEmail((string) $event->event_code, (int) $event->percent);
	$composeUrl = dol_buildpath('/crm/email_compose.php', 1).'?id='.(int) $event->rowid;
	if ($isDraftEmail) {
		print '<td><a href="'.$composeUrl.'">'.dol_escape_htmltag((string) $event->label).'</a></td>';
	} elseif (in_array((string) $event->event_code, array('AC_EMAIL', 'AC_EMAIL_IN'), true) && $user->hasRight('agenda', 'myactions', 'read')) {
		print '<td><a href="'.DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $event->rowid).'">'.dol_escape_htmltag((string) $event->label).'</a></td>';
	} else {
		print '<td>'.dol_escape_htmltag((string) $event->label).'</td>';
	}
	print '<td>'.dol_escape_htmltag((string) $event->type_label).'</td>';
	print '<td>'.((int) $event->percent >= 100 ? $langs->trans('CrmDone') : (int) $event->percent.'%').'</td><td>';
	if ($isDraftEmail) {
		print '<a href="'.$composeUrl.'">'.$langs->trans('CrmWriteSend').'</a> ';
	}
	if ($user->hasRight('crm', 'activity') && (int) $event->percent < 100) {
		print '<a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=complete&eventid='.(int) $event->rowid.'&token='.newToken().'">'.$langs->trans('CrmComplete').'</a>';
	}
	print '</td></tr>';
}
print '</table>';

if (isModEnabled('propal')) {
	print '<h2>'.$langs->trans('CrmProposals').'</h2>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td class="right">'.$langs->trans('CrmAmount').'</td><td></td></tr>';
	foreach ($object->fetchProposals() as $propal) {
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $propal->ref).'</td><td class="right">'.price((float) $propal->total_ht).'</td>';
		print '<td><a href="'.$_SERVER['PHP_SELF'].'?id='.$id.'&action=unlinkpropal&propalid='.(int) $propal->rowid.'&token='.newToken().'">'.$langs->trans('CrmUnlink').'</a></td></tr>';
	}
	print '</table>';
	if ($user->hasRight('crm', 'write')) {
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="linkpropal">';
		print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
		print $langs->trans('CrmProposalId').' <input name="propalid" type="number" min="1"> ';
		print '<input type="submit" class="button" value="'.$langs->trans('CrmLinkProposal').'">';
		print '</form>';
	}
}

print '<h2>'.$langs->trans('CrmHistory').'</h2>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmStage').'</td><td>'.$langs->trans('CrmOwner').'</td></tr>';
foreach ($object->fetchHistory() as $hist) {
	$who = trim((string) $hist->firstname.' '.(string) $hist->lastname);
	$label = trim(crmStageLabel((string) $hist->from_label).' → '.crmStageLabel((string) $hist->to_label), ' →');
	print '<tr class="oddeven"><td>'.dol_print_date($db->jdate($hist->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($label).'</td><td>'.dol_escape_htmltag($who).'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
