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
 * \file    pipeline.php
 * \ingroup crm
 * \brief   Kanban. Drag and drop posts back to this page with the CSRF token.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/pipeline.class.php';
require_once __DIR__.'/class/stage.class.php';
require_once __DIR__.'/class/opportunity.class.php';
require_once __DIR__.'/class/crmreport.class.php';
crmStartPage();

$action = GETPOST('action', 'aZ09');
$pipelineId = GETPOSTINT('pipelineid');
$ownerId = GETPOSTINT('userid');

$pipelines = (new Pipeline($db))->fetchAll(true);
if ($pipelineId <= 0 && count($pipelines) > 0) {
	$pipelineId = (int) $pipelines[0]->id;
}

if ($action === 'move') {
	crmRequireRight('write');
	$deal = new Opportunity($db);
	$id = GETPOSTINT('id');
	if ($deal->fetch($id) > 0 && crmCanReadOwner($user, (int) $deal->fk_user_resp)) {
		$result = $deal->moveToStage($user, GETPOSTINT('stageid'), GETPOSTINT('lossreasonid'), GETPOST('lossnote', 'alphanohtml'));
		if ($result <= 0) {
			setEventMessages(crmErrorText($deal->error), null, 'errors');
		}
	} else {
		setEventMessages($langs->trans('CrmNoRows'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?pipelineid='.$pipelineId.'&userid='.$ownerId);
	exit;
}

$stages = array();
if ($pipelineId > 0) {
	$stages = (new Stage($db))->fetchAll($pipelineId);
}
$deals = array();
if ($pipelineId > 0) {
	$deals = (new Opportunity($db))->fetchList($user, 'open', $pipelineId, $ownerId, '', 0);
}
$byStage = array();
foreach ($deals as $deal) {
	$byStage[(int) $deal->fk_stage][] = $deal;
}
$now = dol_now();

llxHeader('', $langs->trans('CrmPipeline'), '', '', 0, 0, array('/crm/js/pipeline.js'), array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmPipeline'), '', 'fa-handshake');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print $langs->trans('CrmPipeline').' <select name="pipelineid" class="minwidth200">';
foreach ($pipelines as $pipeline) {
	print '<option value="'.((int) $pipeline->id).'"'.($pipeline->id === $pipelineId ? ' selected' : '').'>'.dol_escape_htmltag(crmDictLabel((string) $pipeline->label)).'</option>';
}
print '</select> ';
if ($user->hasRight('crm', 'readall')) {
	print $langs->trans('CrmOwner').' ';
	$form = new Form($db);
	print $form->select_dolusers($ownerId, 'userid', 1, null, 0, '', '', (string) $conf->entity);
}
print ' <input type="submit" class="button" value="'.$langs->trans('CrmFilter').'">';
print '</form>';
print crmHelp($langs->trans('CrmDragHint'));

$boardUrl = dol_escape_htmltag($_SERVER['PHP_SELF']);
print '<div id="crm-board" class="crm-board" data-token="'.dol_escape_htmltag(newToken()).'" data-url="'.$boardUrl.'" data-pipeline="'.((int) $pipelineId).'" data-owner="'.((int) $ownerId).'" data-lost-message="'.dol_escape_htmltag($langs->trans('CrmErrorLossReasonRequired')).'">';
foreach ($stages as $stage) {
	if (!$stage->active) {
		continue;
	}
	$cards = $byStage[(int) $stage->id] ?? array();
	print '<section class="crm-column" data-stage="'.((int) $stage->id).'" data-lost="'.((int) $stage->is_lost).'" style="border-top: 4px solid '.dol_escape_htmltag($stage->color).'">';
	print '<div class="crm-column-head">'.dol_escape_htmltag(crmStageLabel((string) $stage->label));
	print ' <span class="crm-column-meta">'.count($cards).' · '.(int) $stage->probability.'%</span></div>';
	print '<div class="crm-cards">';
	foreach ($cards as $card) {
		$last = 0;
		$created = (int) $db->jdate($card->datec);
		$rotting = crmIsRotting($last, $created, (int) $card->rotting_days, $now);
		if ((int) $card->rotting_days > 0) {
			$latest = CrmActivity::latestDone($db, (int) $card->rowid);
			$rotting = crmIsRotting($latest, $created, (int) $card->rotting_days, $now);
		}
		$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $card->rowid;
		print '<article class="crm-card'.($rotting ? ' crm-rotting' : '').'" draggable="true" data-id="'.((int) $card->rowid).'" data-stage="'.((int) $stage->id).'">';
		print '<div><a href="'.$url.'">'.dol_escape_htmltag((string) $card->ref).'</a></div>';
		print '<div>'.dol_escape_htmltag((string) $card->label).'</div>';
		print '<div>'.dol_escape_htmltag((string) $card->soc_name).'</div>';
		print '<div class="crm-amount">'.price((float) $card->amount).'</div>';
		if ($rotting) {
			print '<div>'.$langs->trans('CrmRotting').'</div>';
		}
		print '</article>';
	}
	print '</div></section>';
}
print '</div>';

crmHelpToggle();
llxFooter();
$db->close();
