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
 * \file    opportunity_list.php
 * \ingroup crm
 * \brief   Deal list.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/opportunity.class.php';
require_once __DIR__.'/class/qualification.class.php';
crmStartPage();

$status = GETPOST('status', 'aZ09');
$search = GETPOST('search_all', 'alphanohtml') !== '' ? GETPOST('search_all', 'alphanohtml') : GETPOST('search', 'alphanohtml');
$ownerId = GETPOSTINT('userid');
if (!in_array($status, array('open', 'won', 'lost', 'all'), true)) {
	$status = 'open';
}

// Sorting and paging, in Dolibarr's usual way: sortfield, sortorder, page and limit.
$sortField = GETPOST('sortfield', 'aZ09');
if (!in_array($sortField, array('ref', 'label', 'account', 'contact', 'stage', 'amount', 'close', 'owner', 'next'), true)) {
	$sortField = 'ref';
}
$sortOrder = (GETPOST('sortorder', 'aZ09') === 'asc') ? 'asc' : (GETPOST('sortorder', 'aZ09') === 'desc' ? 'desc' : ($sortField === 'ref' ? 'desc' : 'asc'));
$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : (int) $conf->liste_limit;
$total = (new Opportunity($db))->countList($user, $status === 'all' ? '' : $status, 0, $ownerId, $search);
$page = max(0, GETPOSTINT('page'));
if ($page * $limit >= $total) {
	$page = 0;
}
$deals = (new Opportunity($db))->fetchList($user, $status === 'all' ? '' : $status, 0, $ownerId, $search, $limit, $page * $limit, $sortField, $sortOrder);
$param = '&status='.urlencode($status).'&search='.urlencode($search).($ownerId > 0 ? '&userid='.$ownerId : '').'&limit='.$limit;
$qual = new Qualification($db);

$newUrl = '';
if ($user->hasRight('crm', 'write')) {
	$newUrl = '<a class="butAction" href="'.dol_buildpath('/crm/opportunity_card.php', 1).'?action=create">'.$langs->trans('CrmNewOpportunity').'</a>';
}

llxHeader('', $langs->trans('CrmOpportunities'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="sortfield" value="'.$sortField.'"><input type="hidden" name="sortorder" value="'.$sortOrder.'">';
print $langs->trans('CrmStatus').' <select name="status">';
foreach (array('open' => 'CrmOpen', 'won' => 'CrmWon', 'lost' => 'CrmLost', 'all' => 'All') as $code => $key) {
	print '<option value="'.$code.'"'.($status === $code ? ' selected' : '').'>'.$langs->trans($key).'</option>';
}
print '</select> ';
print $langs->trans('CrmSearch').' <input type="text" name="search" value="'.dol_escape_htmltag($search).'"> ';
if ($user->hasRight('crm', 'readall')) {
	$form = new Form($db);
	print $form->select_dolusers($ownerId, 'userid', 1, null, 0, '', '', (string) $conf->entity);
}
print ' <input type="submit" class="button" value="'.$langs->trans('CrmFilter').'">';
print_barre_liste($langs->trans('CrmOpportunities'), $page, $_SERVER['PHP_SELF'], $param, $sortField, $sortOrder, '', count($deals), $total, 'fa-handshake', 0, $newUrl, '', $limit);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Ref'), $_SERVER['PHP_SELF'], 'ref', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmTitle'), $_SERVER['PHP_SELF'], 'label', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmAccount'), $_SERVER['PHP_SELF'], 'account', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmContact'), $_SERVER['PHP_SELF'], 'contact', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmStage'), $_SERVER['PHP_SELF'], 'stage', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmAmount'), $_SERVER['PHP_SELF'], 'amount', '', $param, 'class="right"', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmExpectedClose'), $_SERVER['PHP_SELF'], 'close', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmOwner'), $_SERVER['PHP_SELF'], 'owner', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmNextStep'), $_SERVER['PHP_SELF'], 'next', '', $param, '', $sortField, $sortOrder);
print_liste_field_titre($langs->trans('CrmMissing'));
print '</tr>';
if (count($deals) === 0) {
	print '<tr class="oddeven"><td colspan="10">'.$langs->trans('CrmNoRows').'</td></tr>';
}
foreach ($deals as $deal) {
	$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $deal->rowid;
	$items = $qual->fetchForOpportunity((int) $deal->rowid);
	$missing = $qual->missing($items);
	$contact = trim((string) $deal->firstname.' '.(string) $deal->lastname);
	$owner = trim((string) $deal->owner_firstname.' '.(string) $deal->owner_lastname);
	print '<tr class="oddeven">';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $deal->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $deal->label).'</td>';
	print '<td>'.dol_escape_htmltag((string) $deal->soc_name).'</td>';
	print '<td>'.dol_escape_htmltag($contact).'</td>';
	print '<td>'.dol_escape_htmltag(crmStageLabel((string) $deal->stage_label)).'</td>';
	print '<td class="right">'.price((float) $deal->amount).'</td>';
	print '<td>'.dol_print_date($db->jdate($deal->date_close_expected), 'day').'</td>';
	print '<td>'.dol_escape_htmltag($owner).'</td>';
	print '<td>'.dol_print_date($db->jdate($deal->next_date), 'day').'</td>';
	print '<td>'.dol_escape_htmltag(implode(', ', $missing)).'</td>';
	print '</tr>';
}
print '</table>';
print '</form>';

crmHelpToggle();
llxFooter();
$db->close();
