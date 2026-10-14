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

$deals = (new Opportunity($db))->fetchList($user, $status === 'all' ? '' : $status, 0, $ownerId, $search, 200);
$qual = new Qualification($db);

$newUrl = '';
if ($user->hasRight('crm', 'write')) {
	$newUrl = '<a class="butAction" href="'.dol_buildpath('/crm/opportunity_card.php', 1).'?action=create">'.$langs->trans('CrmNewOpportunity').'</a>';
}

llxHeader('', $langs->trans('CrmOpportunities'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmOpportunities'), $newUrl, 'fa-handshake');

print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
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
print '</form>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('CrmTitle').'</td><td>'.$langs->trans('CrmAccount').'</td><td>'.$langs->trans('CrmContact').'</td>';
print '<td>'.$langs->trans('CrmStage').'</td><td class="right">'.$langs->trans('CrmAmount').'</td><td>'.$langs->trans('CrmExpectedClose').'</td>';
print '<td>'.$langs->trans('CrmOwner').'</td><td>'.$langs->trans('CrmNextStep').'</td><td>'.$langs->trans('CrmMissing').'</td>';
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

crmHelpToggle();
llxFooter();
$db->close();
