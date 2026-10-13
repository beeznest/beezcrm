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
 * \file    index.php
 * \ingroup crm
 * \brief   Work to do, then the forecast.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/crmreport.class.php';
crmStartPage();

$report = new CrmReport($db);
$hygiene = $report->hygiene($user);
$forecast = $report->forecast($user);

llxHeader('', $langs->trans('CrmHome'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmHome'), '', 'fa-handshake');

/**
 * @param string            $title   Title
 * @param string            $descKey Language key of the section description
 * @param array<int,object> $rows    Deal rows
 */
$printDeals = function (string $title, string $descKey, array $rows) use ($langs): void {
	print '<h2>'.dol_escape_htmltag($title).' ('.count($rows).')</h2>';
	print '<p class="opacitymedium">'.$langs->trans($descKey).'</p>';
	if (count($rows) === 0) {
		print '<p class="opacitymedium">'.$langs->trans('CrmNoRows').'</p>';
		return;
	}
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('CrmTitle').'</td><td>'.$langs->trans('CrmAccount').'</td><td>'.$langs->trans('CrmStage').'</td></tr>';
	foreach ($rows as $row) {
		$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $row->rowid;
		print '<tr class="oddeven">';
		print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $row->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag((string) $row->label).'</td>';
		print '<td>'.dol_escape_htmltag((string) $row->soc_name).'</td>';
		print '<td>'.dol_escape_htmltag(crmStageLabel((string) $row->stage_label)).'</td>';
		print '</tr>';
	}
	print '</table>';
};

$printDeals($langs->trans('CrmNoNextStep'), 'CrmNoNextStepDesc', $hygiene['no_next']);

print '<h2>'.$langs->trans('CrmOverdue').' ('.count($hygiene['overdue']).')</h2>';
print '<p class="opacitymedium">'.$langs->trans('CrmOverdueDesc').'</p>';
if (count($hygiene['overdue']) === 0) {
	print '<p class="opacitymedium">'.$langs->trans('CrmNoRows').'</p>';
} else {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('CrmActivity').'</td><td>'.$langs->trans('CrmDate').'</td></tr>';
	foreach ($hygiene['overdue'] as $row) {
		$url = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.(int) $row->fk_element;
		print '<tr class="oddeven">';
		print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $row->ref).'</a></td>';
		print '<td>'.dol_escape_htmltag((string) $row->label).'</td>';
		print '<td>'.dol_print_date($db->jdate($row->datep), 'dayhour').'</td>';
		print '</tr>';
	}
	print '</table>';
}

$printDeals($langs->trans('CrmPastClose'), 'CrmPastCloseDesc', $hygiene['past_close']);
$printDeals($langs->trans('CrmRotting'), 'CrmRottingDesc', $hygiene['rotting']);

print '<h2>'.$langs->trans('CrmForecast').'</h2>';
print '<p class="opacitymedium">'.$langs->trans('CrmForecastDesc').'</p>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmForecastCategory').'</td><td class="right">'.$langs->trans('CrmAmount').'</td><td class="right">'.$langs->trans('CrmWeighted').'</td></tr>';
$printed = 0;
foreach ($forecast['months'] as $month => $categories) {
	foreach ($categories as $category => $values) {
		if ($values['amount'] == 0 && $values['weighted'] == 0) {
			continue;
		}
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $month).'</td><td>'.dol_escape_htmltag((string) $category).'</td>';
		print '<td class="right">'.price($values['amount']).'</td><td class="right">'.price($values['weighted']).'</td></tr>';
		$printed++;
	}
}
foreach ($forecast['unscheduled'] as $category => $values) {
	if ($values['amount'] == 0 && $values['weighted'] == 0) {
		continue;
	}
	print '<tr class="oddeven"><td>'.$langs->trans('CrmUnscheduled').'</td><td>'.dol_escape_htmltag((string) $category).'</td>';
	print '<td class="right">'.price($values['amount']).'</td><td class="right">'.price($values['weighted']).'</td></tr>';
	$printed++;
}
if ($printed === 0) {
	print '<tr class="oddeven"><td colspan="4">'.$langs->trans('CrmNoRows').'</td></tr>';
}
print '</table>';

llxFooter();
$db->close();
