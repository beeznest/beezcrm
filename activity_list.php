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
crmRequireToken($action, array());
if ($action === 'complete') {
	crmRequireRight('activity');
	$activity = new CrmActivity($db);
	if ($activity->complete($user, GETPOSTINT('eventid')) <= 0) {
		setEventMessages(crmErrorText($activity->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecordSaved'), null);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?scope='.$scope);
	exit;
}

$sortField = GETPOST('sortfield', 'aZ09');
if (!in_array($sortField, array('date', 'ref', 'label', 'account'), true)) {
	$sortField = 'date';
}
$sortOrder = (GETPOST('sortorder', 'aZ09') === 'desc') ? 'desc' : 'asc';
$limit = min(500, GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : (int) $conf->liste_limit);
$total = (new CrmActivity($db))->countList($user, $scope);
$page = max(0, GETPOSTINT('page'));
if ($page * $limit >= $total) {
	$page = 0;
}
$rows = (new CrmActivity($db))->fetchList($user, $scope, 0, $limit, $page * $limit, $sortField, $sortOrder);
$param = '&scope='.$scope.'&limit='.$limit;

llxHeader('', $langs->trans('CrmActivities'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="scope" value="'.$scope.'">';
print '<input type="hidden" name="sortfield" value="'.$sortField.'"><input type="hidden" name="sortorder" value="'.$sortOrder.'">';
print_barre_liste($langs->trans('CrmActivities'), $page, $_SERVER['PHP_SELF'], $param, $sortField, $sortOrder, '', count($rows), $total, 'fa-handshake', 0, '', '', $limit);

print '<div class="tabsAction">';
foreach (array('overdue' => 'CrmOverdue', 'today' => 'CrmToday', 'planned' => 'CrmPlanned', 'done' => 'CrmDone') as $code => $key) {
	// The unselected filters keep the greyed look of butActionRefused, but they are links that work: crm-scope restores the pointer.
	$class = (($scope === $code) ? 'butAction' : 'butActionRefused').' crm-scope';
	print '<a class="'.$class.'" href="'.$_SERVER['PHP_SELF'].'?scope='.$code.'">'.$langs->trans($key).'</a>';
}
print '</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('CrmDate'), $_SERVER['PHP_SELF'], 'date', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], 'ref', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmTitle'), $_SERVER['PHP_SELF'], 'label', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmAccount'), $_SERVER['PHP_SELF'], 'account', '', $param, '', $sortField, $sortOrder);
print '<td></td></tr>';
if (count($rows) === 0) {
	print '<tr class="oddeven"><td colspan="5">'.$langs->trans('CrmNoRows').'</td></tr>';
}
foreach ($rows as $row) {
	$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $row->fk_element;
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($row->datep), 'dayhour').'</td>';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $row->ref).'</a></td>';
	if (in_array((string) $row->event_code, array('AC_EMAIL', 'AC_EMAIL_IN'), true) && (int) $row->percent >= 100) {
		// A past e-mail: its text is in the message history of the deal.
		print '<td><a href="'.$url.'#crm-msg-'.((int) $row->rowid).'">'.dol_escape_htmltag((string) $row->label).'</a></td>';
	} else {
		print '<td>'.dol_escape_htmltag((string) $row->label).'</td>';
	}
	print '<td>'.dol_escape_htmltag((string) $row->soc_name).'</td><td>';
	if (CrmActivity::isPlannedEmail((string) $row->event_code, (int) $row->percent)) {
		print crmIconLink(dol_buildpath('/crm/email_compose.php', 1).'?id='.(int) $row->rowid, 'fa-envelope', $langs->trans('CrmWriteSend'));
	}
	if ($user->hasRight('crm', 'activity') && (int) $row->percent < 100) {
		print crmIconLink($_SERVER['PHP_SELF'].'?scope='.$scope.'&action=complete&eventid='.(int) $row->rowid.'&token='.newToken(), 'check', $langs->trans('CrmComplete'));
	}
	print '</td></tr>';
}
print '</table>';
print '</form>';

crmHelpToggle();
llxFooter();
$db->close();
