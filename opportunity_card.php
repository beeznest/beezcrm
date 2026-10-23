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
require_once __DIR__.'/class/sequence.class.php';
require_once __DIR__.'/class/crmnote.class.php';
crmStartPage();
$notesOn = CrmNote::supported($db); // From v1.2.0: internal notes replace the description once the tables exist

$action = GETPOST('action', 'aZ09');
crmRequireToken($action, array('askdelactivity', 'editactivity', 'editnote'));
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
$fill = function (Opportunity $deal) use ($user, $notesOn): void {
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
	if (!$notesOn) {
		$deal->description = GETPOST('description', 'restricthtml');
	}
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
		if ($notesOn && trim(GETPOST('firstnote', 'nohtml')) !== '') {
			(new CrmNote($db))->add($user, (int) $newId, GETPOST('firstnote', 'nohtml'));
		}
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

/**
 * Attach the files posted in notefiles[] to a note. Returns the language key of the first error, '' when all went well.
 */
$attachPosted = function (int $noteId) use ($db, $user): string {
	$notes = new CrmNote($db);
	if (empty($_FILES['notefiles']['name']) || !is_array($_FILES['notefiles']['name'])) {
		return '';
	}
	$error = '';
	foreach ($_FILES['notefiles']['name'] as $i => $name) {
		$code = (int) ($_FILES['notefiles']['error'][$i] ?? 0);
		if ((string) $name === '' && $code === UPLOAD_ERR_NO_FILE) {
			continue;
		}
		if ($notes->addFile($user, $noteId, (string) $name, (string) ($_FILES['notefiles']['tmp_name'][$i] ?? ''), true, $code) <= 0 && $error === '') {
			$error = $notes->error;
		}
	}
	return $error;
};

if ($action === 'addnote' && $object->id > 0 && $notesOn) {
	crmRequireRight('write');
	$notes = new CrmNote($db);
	$noteId = $notes->add($user, (int) $object->id, GETPOST('note', 'nohtml'));
	if ($noteId <= 0) {
		setEventMessages(crmErrorText($notes->error), null, 'errors');
	} else {
		$fileError = $attachPosted($noteId);
		setEventMessages($fileError === '' ? $langs->trans('RecordSaved') : crmErrorText($fileError), null, $fileError === '' ? 'mesgs' : 'errors');
	}
	header('Location: '.$back.'#crm-notes');
	exit;
}

if ($action === 'savenote' && $object->id > 0 && $notesOn) {
	crmRequireRight('write');
	$notes = new CrmNote($db);
	$noteId = GETPOSTINT('noteid');
	$existing = $notes->fetch($user, $noteId);
	if ($existing === null || (int) $existing->fk_opportunity !== (int) $object->id) {
		accessforbidden();
	}
	$result = $notes->update($user, $noteId, GETPOST('note', 'nohtml'));
	$fileError = ($result >= 0) ? $attachPosted($noteId) : '';
	if ($result < 0) {
		setEventMessages(crmErrorText($notes->error), null, 'errors');
	} elseif ($fileError !== '') {
		setEventMessages(crmErrorText($fileError), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back.'#crm-notes');
	exit;
}

if ($action === 'delnotefile' && $object->id > 0 && $notesOn) {
	crmRequireRight('write');
	$notes = new CrmNote($db);
	$file = $notes->fetchFile($user, GETPOSTINT('fileid'));
	if ($file === null || (int) $file->fk_opportunity !== (int) $object->id) {
		accessforbidden();
	}
	if ($notes->deleteFile($user, (int) $file->rowid) <= 0) {
		setEventMessages(crmErrorText($notes->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back.'#crm-notes');
	exit;
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
		setEventMessages(crmErrorText($qualSave->error), null, 'errors');
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
		setEventMessages(crmErrorText($activity->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'complete' && $object->id > 0) {
	crmRequireRight('activity');
	$activity = new CrmActivity($db);
	if ($activity->complete($user, GETPOSTINT('eventid'), (int) $object->id) <= 0) {
		setEventMessages(crmErrorText($activity->error), null, 'errors');
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'updateactivity' && $object->id > 0) {
	crmRequireRight('activity');
	$when = GETPOSTDATE('actdate', 'getpost');
	$activity = new CrmActivity($db);
	if ($activity->updateStep($user, GETPOSTINT('eventid'), GETPOST('kind', 'aZ09'), GETPOST('actlabel', 'alphanohtml'), is_numeric($when) ? (int) $when : 0, (int) $object->id) <= 0) {
		setEventMessages(crmErrorText($activity->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'confirm_delactivity' && GETPOST('confirm', 'alpha') === 'yes' && $object->id > 0) {
	crmRequireRight('activity');
	$activity = new CrmActivity($db);
	if ($activity->deleteStep($user, GETPOSTINT('eventid'), (int) $object->id) <= 0) {
		setEventMessages(crmErrorText($activity->error), null, 'errors');
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'enroloutreach' && $object->id > 0) {
	crmRequireRight('sequence');
	$outreach = new Sequence($db);
	if (GETPOSTINT('sequenceid') <= 0 || $outreach->fetch(GETPOSTINT('sequenceid')) <= 0) {
		setEventMessages($langs->trans('CrmErrorSequenceNotFound'), null, 'errors');
	} elseif ($outreach->enrolFromDeal((int) $object->id) <= 0) {
		setEventMessages(crmErrorText($outreach->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('CrmOutreachEnrolledOk'), null);
	}
	header('Location: '.$back);
	exit;
}

if ($action === 'linkpropal' && $object->id > 0) {
	crmRequireRight('write');
	if ($object->linkProposal($user, GETPOSTINT('propalid')) <= 0) {
		setEventMessages(crmErrorText($object->error), null, 'errors');
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
	setEventMessages(crmErrorText($object->error), null, 'errors');
}

$pipelines = (new Pipeline($db))->fetchAll(true);
// Every source, so that a deal keeps the label of a source turned off since; the form offers the active ones.
$sources = crmFetchDictionary($db, 'source', false);
$reasons = crmFetchDictionary($db, 'loss_reason', true);
$stages = ($object->fk_pipeline > 0) ? (new Stage($db))->fetchAll((int) $object->fk_pipeline) : array();

llxHeader('', $langs->trans('CrmOpportunity'), '', '', 0, 0, '', array('/crm/css/crm.css'));

if ($action === 'askdelactivity' && $object->id > 0 && $user->hasRight('crm', 'activity')) {
	$askEvent = (new CrmActivity($db))->fetchPlanned(GETPOSTINT('eventid'), (int) $object->id);
	if ($askEvent !== null) {
		print $form->formconfirm($back.'&eventid='.(int) $askEvent->rowid, $langs->trans('Delete'), $langs->trans('CrmConfirmDeleteActivity', dol_escape_htmltag((string) $askEvent->label)), 'confirm_delactivity', '', 0, 1);
	}
}

if ($action === 'delete' && $object->id > 0) {
	print $form->formconfirm($back, $langs->trans('Delete'), $langs->trans('CrmConfirmDelete'), 'confirm_delete', '', 0, 1);
}

// Back from the third party creation form: socid is the new prospect, the other fields were carried along.
if ($action === 'create' && $object->id <= 0 && (GETPOSTISSET('socid') || GETPOSTISSET('label'))) {
	$fill($object);
}

if ($action === 'create' && !GETPOSTISSET('datecloseyear') && (int) $object->date_close_expected <= 0) {
	$todayParts = dol_getdate(dol_now());
	// A month from today. dol_mktime() refuses month 13 and a day such as 31 February, dol_time_plus_duree() does not.
	$object->date_close_expected = (int) dol_time_plus_duree((int) dol_mktime(0, 0, 0, (int) $todayParts['mon'], (int) $todayParts['mday'], (int) $todayParts['year']), 1, 'm');
}

if ($action === 'create' || $action === 'edit') {
	$creating = ($action === 'create');
	print load_fiche_titre($creating ? $langs->trans('CrmNewOpportunity') : dol_escape_htmltag($object->ref), '', 'fa-handshake');
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
	print '<script nonce="'.dol_escape_htmltag(getNonce()).'">
	jQuery(function () {
		jQuery("#crm-new-prospect").on("click", function (event) {
			var form = jQuery(this).closest("form");
			var back = ' . json_encode($backToCreate) . ';
			jQuery.each(["label", "pipelineid", "amount", "sourceid", "userid", "description", "firstnote"], function (i, name) {
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
	print crmHelp($langs->trans('CrmAccountHelp'), 'span', true).'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmContact').'</td><td>'.$form->selectcontacts((int) $object->fk_soc, (int) $object->fk_socpeople, 'contactid', 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmAmount').'</td><td><input name="amount" value="'.price((float) $object->amount).'"></td></tr>';
	print '<tr><td>'.$langs->trans('CrmExpectedClose').'</td><td>'.$form->selectDate((int) $object->date_close_expected, 'dateclose', 0, 0, 1).'</td></tr>';
	print '<tr><td>'.$langs->trans('CrmSource').'</td><td><select name="sourceid"><option value="0"></option>';
	foreach ($sources as $source) {
		if (!$source->active && (int) $object->fk_source !== (int) $source->rowid) {
			continue;
		}
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
	if (!$notesOn) {
		print '<tr><td>'.$langs->trans('CrmDescription').'</td><td><textarea name="description" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag((string) $object->description, 0, 1).'</textarea></td></tr>';
	} elseif ($creating) {
		print '<tr><td>'.$langs->trans('CrmFirstNote').'</td><td><textarea name="firstnote" class="quatrevingtpercent" rows="4">'.dol_escape_htmltag(GETPOST('firstnote', 'nohtml'), 0, 1).'</textarea></td></tr>';
	}
	print '</table>';
	print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
	crmHelpToggle();
	llxFooter();
	$db->close();
	return;
}

print load_fiche_titre(dol_escape_htmltag($object->ref.' — '.$object->label), '', 'fa-handshake');
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
if (!$notesOn) {
	if (trim((string) $object->description) !== '') {
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('CrmDescription').'</td><td>'.dol_nl2br(dol_escape_htmltag((string) $object->description, 0, 1)).'</td></tr>';
		print '</table>';
	}
} else {
	// From v1.2.0: internal notes. The last one is shown, the others behind a link. Each keeps its author, its date, its files
	// and the versions it had before an edit.
	$noteModel = new CrmNote($db);
	$noteRows = $noteModel->fetchForOpportunity($user, (int) $object->id);
	$noteFiles = $noteModel->fetchFilesForOpportunity($user, (int) $object->id);
	$editNoteId = ($action === 'editnote') ? GETPOSTINT('noteid') : 0;
	$fileUrl = dol_buildpath('/crm/note_file.php', 1);
	$whoName = function (int $uid, $row = null) use ($db): string {
		if ($row !== null && (trim((string) $row->firstname.(string) $row->lastname) !== '' || (string) $row->login !== '')) {
			return trim((string) $row->firstname.' '.(string) $row->lastname) !== '' ? trim((string) $row->firstname.' '.(string) $row->lastname) : (string) $row->login;
		}
		return $uid > 0 ? '#'.$uid : '';
	};
	// The preview of a PDF is the magnifier icon of Dolibarr's own document lists: it opens the file in a dialog of the page
	// (an iframe on the same page, inline), and falls back to a new tab without JavaScript. The name of the file downloads it.
	$previewLink = function (object $file) use ($fileUrl, $langs): string {
		if (strtolower((string) pathinfo((string) $file->filename, PATHINFO_EXTENSION)) !== 'pdf') {
			return '';
		}
		$title = $langs->trans('Preview').' '.(string) $file->filename;
		return ' <a href="'.dol_escape_htmltag($fileUrl.'?id='.((int) $file->rowid).'&mode=view').'" class="crm-preview" target="_blank" rel="noopener" data-title="'.dol_escape_htmltag((string) $file->filename).'" title="'.dol_escape_htmltag($title).'" aria-label="'.dol_escape_htmltag($title).'">'.img_picto('', 'search-plus').'</a>';
	};
	$hasPdf = false;
	foreach ($noteFiles as $previewable) {
		if (strtolower((string) pathinfo((string) $previewable->filename, PATHINFO_EXTENSION)) === 'pdf') {
			$hasPdf = true;
		}
	}
	$filesLink = '';
	if (count($noteFiles) > 0) {
		$filesLink = ' <a href="#" id="crm-files-open" class="crm-files-link" title="'.dol_escape_htmltag($langs->trans('CrmNoteAllFiles')).'">'.img_picto('', 'fa-paperclip', 'class="pictofixedwidth"').count($noteFiles).'</a>';
	}
	print '<a id="crm-notes"></a><h2>'.$langs->trans('CrmInternalNotes').$filesLink.'</h2>';
	$renderNote = function (object $note) use ($db, $user, $noteModel, $whoName, $fileUrl, $previewLink, $editNoteId, $id, $langs): void {
		$noteId = (int) $note->rowid;
		print '<div class="crm-note" id="crm-note-'.$noteId.'">';
		print '<div class="crm-note-head"><strong>'.dol_escape_htmltag($whoName((int) $note->fk_user, $note)).'</strong> <span class="opacitymedium">'.dol_print_date($db->jdate($note->datec), 'dayhour').'</span>';
		if ($note->date_modif !== null) {
			$editor = new User($db);
			$editorName = ((int) $note->fk_user_modif > 0 && $editor->fetch((int) $note->fk_user_modif) > 0) ? trim((string) $editor->firstname.' '.(string) $editor->lastname) : '';
			print ' <span class="opacitymedium">('.dol_escape_htmltag($langs->transnoentities('CrmNoteEdited', dol_print_date($db->jdate($note->date_modif), 'dayhour'), $editorName !== '' ? $editorName : (string) $editor->login)).')</span>';
		}
		if ((int) $note->revisions > 0) {
			print ' <a href="#" class="crm-note-history-toggle" data-target="crm-note-history-'.$noteId.'">'.dol_escape_htmltag($langs->transnoentities('CrmNoteHistory', (int) $note->revisions)).'</a>';
		}
		if ($noteModel->canEdit($user, $note) && $editNoteId !== $noteId) {
			print ' '.crmIconLink($_SERVER['PHP_SELF'].'?id='.$id.'&action=editnote&noteid='.$noteId.'&token='.newToken().'#crm-note-'.$noteId, 'edit', $langs->trans('Modify'));
		}
		print '</div>';
		if ($editNoteId === $noteId && $noteModel->canEdit($user, $note)) {
			print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'">';
			print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="savenote">';
			print '<input type="hidden" name="id" value="'.((int) $id).'"><input type="hidden" name="noteid" value="'.$noteId.'">';
			print '<textarea name="note" class="quatrevingtpercent" rows="5" required>'.dol_escape_htmltag((string) $note->note, 0, 1).'</textarea><br>';
			print '<input type="file" name="notefiles[]" multiple> ';
			print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('Save')).'"> <a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?id='.$id.'#crm-note-'.$noteId).'">'.$langs->trans('Cancel').'</a>';
			print '</form>';
		} else {
			print '<div class="crm-note-text">'.dol_nl2br(dol_escape_htmltag((string) $note->note, 0, 1)).'</div>';
		}
		if (count($note->files) > 0) {
			print '<ul class="crm-note-files">';
			foreach ($note->files as $file) {
				print '<li>'.img_picto('', 'fa-paperclip', 'class="pictofixedwidth"').'<a href="'.dol_escape_htmltag($fileUrl.'?id='.((int) $file->rowid)).'">'.dol_escape_htmltag((string) $file->filename).'</a> <span class="opacitymedium">('.dol_print_size((int) $file->filesize, 1, 1).')</span>'.$previewLink($file);
				if ($editNoteId === $noteId) {
					print ' '.crmIconLink($_SERVER['PHP_SELF'].'?id='.$id.'&action=delnotefile&fileid='.((int) $file->rowid).'&token='.newToken(), 'delete', $langs->trans('Delete'));
				}
				print '</li>';
			}
			print '</ul>';
		}
		if ((int) $note->revisions > 0) {
			print '<div class="crm-note-history" id="crm-note-history-'.$noteId.'" style="display:none"><table class="noborder centpercent">';
			print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmNoteAuthor').'</td><td>'.$langs->trans('CrmNoteVersion').'</td></tr>';
			foreach ($noteModel->fetchVersions($user, $noteId) as $version) {
				print '<tr class="oddeven"><td class="nowraponall">'.dol_print_date($db->jdate($version->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($whoName((int) $version->fk_user, $version)).'</td>';
				print '<td>'.($version->current ? '<span class="badge badge-status4">'.dol_escape_htmltag($langs->trans('CrmNoteCurrent')).'</span> ' : '').dol_nl2br(dol_escape_htmltag((string) $version->note, 0, 1)).'</td></tr>';
			}
			print '</table></div>';
		}
		print '</div>';
	};
	// Chronological order: the older notes (oldest first) are folded above the last one, which stays visible.
	if (count($noteRows) === 0) {
		print '<p class="opacitymedium">'.$langs->trans('CrmNoNotes').'</p>';
	}
	if (count($noteRows) > 1) {
		$olderNotes = array_reverse(array_slice($noteRows, 1));
		$olderOpen = false;
		foreach ($olderNotes as $note) {
			if ($editNoteId === (int) $note->rowid) {
				$olderOpen = true;
			}
		}
		print '<p><a href="#" id="crm-notes-toggle" data-more="'.dol_escape_htmltag($langs->trans('CrmNotesShowOlder', count($olderNotes))).'" data-less="'.dol_escape_htmltag($langs->trans('CrmNotesHideOlder')).'">'.dol_escape_htmltag($olderOpen ? $langs->trans('CrmNotesHideOlder') : $langs->trans('CrmNotesShowOlder', count($olderNotes))).'</a></p>';
		print '<div id="crm-notes-older"'.($olderOpen ? '' : ' style="display:none"').'>';
		foreach ($olderNotes as $note) {
			$renderNote($note);
		}
		print '</div>';
	}
	if (count($noteRows) > 0) {
		$renderNote($noteRows[0]);
	}
	if ($user->hasRight('crm', 'write')) {
		print '<form method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="addnote">';
		print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
		print '<textarea name="note" class="quatrevingtpercent" rows="3" placeholder="'.dol_escape_htmltag($langs->trans('CrmNotePlaceholder')).'" required></textarea><br>';
		print '<input type="file" name="notefiles[]" multiple> <span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CrmNoteFilesMax', dol_print_size(CrmNote::maxFileBytes(), 1, 1))).'</span> ';
		print '<input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CrmAddNote')).'">';
		print '</form>';
	}
	if (count($noteFiles) > 0) {
		print '<dialog id="crm-files-dialog" class="crm-files-dialog"><h3>'.dol_escape_htmltag($langs->trans('CrmNoteAllFiles')).'</h3>';
		print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmNoteFile').'</td><td class="right">'.$langs->trans('CrmNoteSize').'</td><td>'.$langs->trans('CrmNoteUploaded').'</td><td>'.$langs->trans('CrmNoteAuthor').'</td></tr>';
		foreach ($noteFiles as $file) {
			print '<tr class="oddeven"><td><a href="'.dol_escape_htmltag($fileUrl.'?id='.((int) $file->rowid)).'">'.dol_escape_htmltag((string) $file->filename).'</a>'.$previewLink($file);
			print '</td><td class="right nowraponall">'.dol_print_size((int) $file->filesize, 1, 1).'</td><td class="nowraponall">'.dol_print_date($db->jdate($file->datec), 'dayhour').'</td><td>'.dol_escape_htmltag($whoName((int) $file->fk_user, $file)).' <a href="#crm-note-'.((int) $file->fk_note).'" class="crm-files-close-link">#'.((int) $file->fk_note).'</a></td></tr>';
		}
		print '</table><p class="center"><input type="button" class="button" id="crm-files-close" value="'.dol_escape_htmltag($langs->trans('CloseWindow')).'"></p></dialog>';
	}
	if ($hasPdf) {
		print '<dialog id="crm-preview-dialog" class="crm-preview-dialog"><div class="crm-preview-head"><strong id="crm-preview-title"></strong> <input type="button" class="button" id="crm-preview-close" value="'.dol_escape_htmltag($langs->trans('CloseWindow')).'"></div>';
		print '<iframe id="crm-preview-frame" class="crm-preview-frame" title="'.dol_escape_htmltag($langs->trans('Preview')).'"></iframe></dialog>';
	}
	print '<script nonce="'.dol_escape_htmltag(getNonce()).'">
	jQuery(function () {
		jQuery("#crm-notes-toggle").on("click", function (event) {
			event.preventDefault();
			var box = jQuery("#crm-notes-older");
			box.toggle();
			jQuery(this).text(box.is(":visible") ? jQuery(this).data("less") : jQuery(this).data("more"));
		});
		jQuery(".crm-note-history-toggle").on("click", function (event) {
			event.preventDefault();
			jQuery("#" + jQuery(this).data("target")).toggle();
		});
		var dialog = document.getElementById("crm-files-dialog");
		jQuery("#crm-files-open").on("click", function (event) {
			event.preventDefault();
			if (dialog && dialog.showModal) { dialog.showModal(); } else if (dialog) { dialog.setAttribute("open", "open"); }
		});
		var preview = document.getElementById("crm-preview-dialog");
		jQuery(document).on("click", "a.crm-preview", function (event) {
			if (!preview || !preview.showModal) { return; }
			event.preventDefault();
			document.getElementById("crm-preview-title").textContent = jQuery(this).data("title");
			document.getElementById("crm-preview-frame").setAttribute("src", this.href);
			preview.showModal();
		});
		jQuery("#crm-preview-close").on("click", function () { preview.close(); });
		if (preview) {
			preview.addEventListener("close", function () { document.getElementById("crm-preview-frame").setAttribute("src", "about:blank"); });
		}
		jQuery("#crm-files-close, .crm-files-close-link").on("click", function () {
			if (dialog && dialog.close) { dialog.close(); } else if (dialog) { dialog.removeAttribute("open"); }
			jQuery("#crm-notes-older").show();
		});
	});
	</script>';
}

// Sections, in the order a salesperson works them: next step, qualification, stage, proposals, then the history.
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
	if ($action === 'editactivity' && GETPOSTINT('eventid') === (int) $event->rowid && (int) $event->percent < 100 && $user->hasRight('crm', 'activity')) {
		$editIsEmail = ((string) $event->event_code === 'AC_EMAIL');
		$editKind = ((string) $event->code === 'AC_TEL') ? 'call' : (((string) $event->code === 'AC_RDV') ? 'meeting' : 'task');
		print '<tr class="oddeven"><td colspan="5"><form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="updateactivity">';
		print '<input type="hidden" name="id" value="'.((int) $object->id).'"><input type="hidden" name="eventid" value="'.((int) $event->rowid).'">';
		if ($editIsEmail) {
			// The subject of a planned e-mail is edited in the e-mail editor; here only the date can change.
			print dol_escape_htmltag((string) $event->label).' ';
		} else {
			print '<select name="kind">';
			foreach (array('call' => 'CrmCall', 'meeting' => 'CrmMeeting', 'task' => 'CrmTask') as $kind => $key) {
				print '<option value="'.$kind.'"'.($kind === $editKind ? ' selected' : '').'>'.$langs->trans($key).'</option>';
			}
			print '</select> <input name="actlabel" class="minwidth200" required value="'.dol_escape_htmltag((string) $event->label).'"> ';
		}
		print $form->selectDate((int) $db->jdate($event->datep), 'actdate', 1, 1, 0);
		print ' <input type="submit" class="button" value="'.$langs->trans('Save').'"> <a class="button button-cancel" href="'.dol_escape_htmltag($back).'">'.$langs->trans('Cancel').'</a>';
		print '</form></td></tr>';
		continue;
	}
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
		print crmIconLink($composeUrl, 'fa-envelope', $langs->trans('CrmWriteSend'));
	}
	if ($user->hasRight('crm', 'activity') && (int) $event->percent < 100) {
		print crmIconLink($_SERVER['PHP_SELF'].'?id='.$id.'&action=complete&eventid='.(int) $event->rowid.'&token='.newToken(), 'check', $langs->trans('CrmComplete'));
		print crmIconLink($_SERVER['PHP_SELF'].'?id='.$id.'&action=editactivity&eventid='.(int) $event->rowid.'&token='.newToken(), 'edit', $langs->trans('Modify'));
		print crmIconLink($_SERVER['PHP_SELF'].'?id='.$id.'&action=askdelactivity&eventid='.(int) $event->rowid.'&token='.newToken(), 'delete', $langs->trans('Delete'));
	}
	print '</td></tr>';
}
print '</table>';

// The automated outreach this lead follows, and the means to enrol it in one. An enrolment starts at the moment it is saved.
$outreachModel = new Sequence($db);
$enrolments = $outreachModel->fetchEnrolmentsForOpportunity((int) $object->id);
$followsOutreach = $outreachModel->hasRunningEnrolment((int) $object->id);
print '<h2>'.$langs->trans('CrmSequences').'</h2>';
print crmHelp($langs->trans('CrmOutreachHelp'));
if (count($enrolments) === 0) {
	print '<p class="opacitymedium">'.$langs->trans('CrmOutreachNone').'</p>';
} else {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('CrmSequence').'</td><td>'.$langs->trans('CrmStatus').'</td><td>'.$langs->trans('CrmOutreachSince').'</td><td>'.$langs->trans('CrmNextStep').'</td><td>'.$langs->trans('CrmOutreachDue').'</td></tr>';
	foreach ($enrolments as $enrol) {
		$running = ((string) $enrol->status === 'running');
		print '<tr class="oddeven">';
		print '<td><a href="'.dol_buildpath('/crm/sequence_card.php', 1).'?id='.(int) $enrol->fk_sequence.'">'.dol_escape_htmltag((string) $enrol->seq_ref).'</a> '.dol_escape_htmltag(crmDictLabel((string) $enrol->seq_label)).'</td>';
		print '<td>'.dol_escape_htmltag(crmEnrolStatusLabel((string) $enrol->status, (string) $enrol->stop_reason)).'</td>';
		print '<td>'.dol_print_date($db->jdate($enrol->date_enrol), 'dayhour').'</td>';
		print '<td>'.($running ? dol_escape_htmltag($langs->trans('CrmOutreachStepN', (string) (int) $enrol->current_step).(trim((string) $enrol->step_subject) !== '' ? ': '.trim((string) $enrol->step_subject) : '')) : '').'</td>';
		print '<td>'.($running && !empty($enrol->date_next) ? dol_print_date($db->jdate($enrol->date_next), 'dayhour') : '').'</td>';
		print '</tr>';
	}
	print '</table>';
}
if ($user->hasRight('crm', 'sequence') && !$followsOutreach && (string) $object->status === 'open') {
	$enrollable = $outreachModel->fetchEnrollable();
	if (count($enrollable) === 0) {
		print '<p class="opacitymedium">'.$langs->trans('CrmOutreachNoneAvailable').'</p>';
	} else {
		$outreachContactEmail = '';
		if ((int) $object->fk_socpeople > 0) {
			$outreachContact = new Contact($db);
			if ($outreachContact->fetch((int) $object->fk_socpeople) > 0) {
				$outreachContactEmail = trim((string) $outreachContact->email);
			}
		}
		if ($outreachContactEmail === '') {
			print '<div class="warning">'.$langs->trans('CrmOutreachNoContactEmail').'</div>';
		}
		print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="enroloutreach">';
		print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
		print '<select name="sequenceid" required><option value="">'.dol_escape_htmltag($langs->trans('CrmOutreachChoose')).'</option>';
		foreach ($enrollable as $row) {
			print '<option value="'.((int) $row->rowid).'">'.dol_escape_htmltag((string) $row->ref.' — '.crmDictLabel((string) $row->label)).'</option>';
		}
		print '</select> <input type="submit" class="button" value="'.dol_escape_htmltag($langs->trans('CrmOutreachEnrolButton')).'">';
		print '</form>';
	}
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

if ($user->hasRight('crm', 'write')) {
	print '<h2>'.$langs->trans('CrmStage').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="move">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
	$lostSelected = ($currentStage && (int) $currentStage->is_lost === 1);
	print '<select name="stageid" id="crm-stageid">';
	foreach ($stages as $stage) {
		if (!$stage->active && (int) $object->fk_stage !== (int) $stage->id) {
			continue;
		}
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
		print crmHelp($langs->trans('CrmMoveNextStepHelp'), 'span', true);
	}
	print '</form>';
}

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

// The e-mails that sequences made for this deal, and on what ground: which sequence, which step, which day.
$sequenceMails = (new Sequence($db))->fetchLogForOpportunity((int) $object->id);
if (count($sequenceMails) > 0) {
	print '<h2>'.$langs->trans('CrmSequenceMails').'</h2>';
	print crmHelp($langs->trans('CrmSequenceMailsHelp'));
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmSequence').'</td><td>'.$langs->trans('CrmLogWhyTitle').'</td><td>'.$langs->trans('CrmEmail').'</td><td>'.$langs->trans('CrmSubject').'</td><td>'.$langs->trans('CrmStatus').'</td><td></td></tr>';
	foreach ($sequenceMails as $mail) {
		print '<tr class="oddeven">';
		print '<td>'.dol_print_date($db->jdate($mail->date_send), 'dayhour').'</td>';
		print '<td><a href="'.dol_buildpath('/crm/sequence_card.php', 1).'?id='.(int) $mail->fk_sequence.'">'.dol_escape_htmltag((string) $mail->seq_ref).'</a> '.dol_escape_htmltag((string) $mail->seq_label).'</td>';
		print '<td>'.dol_escape_htmltag(crmLogWhy($mail)).'</td>';
		print '<td>'.dol_escape_htmltag((string) $mail->email).'</td>';
		print '<td>'.dol_escape_htmltag((string) $mail->subject).'</td>';
		print '<td>'.dol_escape_htmltag(crmLogStatusLabel((string) $mail->status)).($mail->error_msg !== null && (string) $mail->error_msg !== '' ? ' <span class="opacitymedium">('.dol_escape_htmltag((string) $mail->error_msg).')</span>' : '').'</td>';
		print '<td class="right">'.crmLogViewLink((int) $mail->rowid).'</td>';
		print '</tr>';
	}
	print '</table>';
}

crmHelpToggle();
llxFooter();
$db->close();
