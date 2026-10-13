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
 * \file    activity_list.php
 * \ingroup crm
 * \brief   Agenda events linked to deals. This page does not store a second calendar.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/crmactivity.class.php';
crmStartPage();

$scope = GETPOST('scope', 'aZ09');
if (!in_array($scope, array('overdue', 'today', 'planned', 'done'), true)) {
	$scope = 'overdue';
}
$action = GETPOST('action', 'aZ09');
if ($action === 'complete') {
	crmRequireRight('activity');
	$activity = new CrmActivity($db);
	if ($activity->complete($user, GETPOSTINT('eventid')) <= 0) {
		setEventMessages($activity->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?scope='.$scope);
	exit;
}

$rows = (new CrmActivity($db))->fetchList($user, $scope);

llxHeader('', $langs->trans('CrmActivities'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmActivities'), '', 'fa-handshake');

print '<div class="tabsAction">';
foreach (array('overdue' => 'CrmOverdue', 'today' => 'CrmToday', 'planned' => 'CrmPlanned', 'done' => 'CrmDone') as $code => $key) {
	$class = ($scope === $code) ? 'butAction' : 'butActionRefused';
	print '<a class="'.$class.'" href="'.$_SERVER['PHP_SELF'].'?scope='.$code.'">'.$langs->trans($key).'</a>';
}
print '</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('CrmTitle').'</td><td>'.$langs->trans('CrmAccount').'</td><td></td></tr>';
if (count($rows) === 0) {
	print '<tr class="oddeven"><td colspan="5">'.$langs->trans('CrmNoRows').'</td></tr>';
}
foreach ($rows as $row) {
	$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $row->fk_element;
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($row->datep), 'dayhour').'</td>';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $row->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $row->label).'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->soc_name).'</td><td>';
	if (CrmActivity::isPlannedEmail((string) $row->event_code, (int) $row->percent)) {
		print '<a href="'.dol_buildpath('/crm/email_compose.php', 1).'?id='.(int) $row->rowid.'">'.$langs->trans('CrmWriteSend').'</a> ';
	}
	if ($user->hasRight('crm', 'activity') && (int) $row->percent < 100) {
		print '<a href="'.$_SERVER['PHP_SELF'].'?scope='.$scope.'&action=complete&eventid='.(int) $row->rowid.'&token='.newToken().'">'.$langs->trans('CrmComplete').'</a>';
	}
	print '</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
