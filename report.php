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
 * \file    report.php
 * \ingroup crm
 * \brief   Forecast, cohort conversion, win rate, loss reasons, cycle time.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/crmreport.class.php';
require_once __DIR__.'/class/pipeline.class.php';
crmStartPage();
crmRequireRight('report');

$fromRaw = GETPOSTDATE('datefrom');
$toRaw = GETPOSTDATE('dateto');
$from = is_numeric($fromRaw) && (int) $fromRaw > 0 ? (int) $fromRaw : (int) dol_mktime(0, 0, 0, 1, 1, (int) dol_print_date(dol_now(), '%Y'));
$to = is_numeric($toRaw) && (int) $toRaw > 0 ? (int) $toRaw + 86400 : crmTodayStart() + 86400;
$pipelineId = GETPOSTINT('pipelineid');
$pipelines = (new Pipeline($db))->fetchAll(true);
if ($pipelineId <= 0 && count($pipelines) > 0) {
	$pipelineId = (int) $pipelines[0]->id;
}

$report = new CrmReport($db);
$forecast = $report->forecast($user);
$conversion = $report->conversion($user, $pipelineId, $from, $to);
$win = $report->winRate($user, $from, $to);
$cycle = $report->cycleTime($user, $from, $to);
$reasons = $report->lossReasons($user, $from, $to);
$byOwner = $report->byOwner($user, $from, $to);
$bySource = $report->bySource($user, $from, $to);

llxHeader('', $langs->trans('CrmReports'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmReports'), '', 'fa-handshake');

$form = new Form($db);
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print $langs->trans('CrmFrom').' '.$form->selectDate($from, 'datefrom', 0, 0, 0).' ';
print $langs->trans('CrmTo').' '.$form->selectDate($to - 86400, 'dateto', 0, 0, 0).' ';
print '<select name="pipelineid">';
foreach ($pipelines as $pipeline) {
	print '<option value="'.((int) $pipeline->id).'"'.($pipeline->id === $pipelineId ? ' selected' : '').'>'.dol_escape_htmltag(crmDictLabel((string) $pipeline->label)).'</option>';
}
print '</select> ';
print '<input type="submit" class="button" value="'.$langs->trans('CrmFilter').'">';
print '</form>';

print '<h2>'.$langs->trans('CrmForecast').'</h2>';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmForecastCategory').'</td><td class="right">'.$langs->trans('CrmAmount').'</td><td class="right">'.$langs->trans('CrmWeighted').'</td></tr>';
foreach ($forecast['months'] as $month => $categories) {
	foreach ($categories as $category => $values) {
		if ($values['amount'] == 0) {
			continue;
		}
		print '<tr class="oddeven"><td>'.dol_escape_htmltag((string) $month).'</td><td>'.dol_escape_htmltag((string) $category).'</td><td class="right">'.price($values['amount']).'</td><td class="right">'.price($values['weighted']).'</td></tr>';
	}
}
print '</table>';

print '<h2>'.$langs->trans('CrmCohort').' ('.$conversion['cohort'].')</h2>';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmStage').'</td><td class="right">'.$langs->trans('CrmReached').'</td><td class="right">'.$langs->trans('CrmRate').'</td></tr>';
foreach ($conversion['stages'] as $stage) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag(crmStageLabel((string) $stage['label'])).'</td><td class="right">'.(int) $stage['reached'].'</td><td class="right">'.round($stage['rate'] * 100).'%</td></tr>';
}
print '</table>';

print '<h2>'.$langs->trans('CrmWinRate').'</h2>';
print '<p>'.$win['won'].' / '.($win['won'] + $win['lost']).' = '.round($win['rate'] * 100).'%</p>';
print '<h2>'.$langs->trans('CrmCycleTime').'</h2>';
print '<p>'.round($cycle['days'], 1).' '.$langs->trans('CrmDays').' ('.$cycle['count'].')</p>';

print '<h2>'.$langs->trans('CrmLossReasons').'</h2>';
print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmLossReason').'</td><td class="right">'.$langs->trans('CrmCount').'</td><td class="right">'.$langs->trans('CrmAmount').'</td></tr>';
foreach ($reasons as $reason) {
	$label = $reason['label'] !== '' ? $reason['label'] : $langs->trans('CrmNone');
	print '<tr class="oddeven"><td>'.dol_escape_htmltag(crmDictLabel($label)).'</td><td class="right">'.(int) $reason['nb'].'</td><td class="right">'.price($reason['amount']).'</td></tr>';
}
print '</table>';

$printDim = function (string $title, array $rows) use ($langs): void {
	print '<h2>'.dol_escape_htmltag($title).'</h2>';
	print '<table class="noborder centpercent"><tr class="liste_titre"><td>'.$langs->trans('CrmLabel').'</td><td class="right">'.$langs->trans('CrmNbOpen').'</td><td class="right">'.$langs->trans('CrmAmountOpen').'</td><td class="right">'.$langs->trans('CrmWeighted').'</td><td class="right">'.$langs->trans('CrmNbWon').'</td><td class="right">'.$langs->trans('CrmNbLost').'</td></tr>';
	foreach ($rows as $row) {
		$label = $row['label'] !== '' ? $row['label'] : $langs->trans('CrmNone');
		print '<tr class="oddeven"><td>'.dol_escape_htmltag(crmDictLabel($label)).'</td><td class="right">'.(int) $row['nb_open'].'</td>';
		print '<td class="right">'.price($row['amount_open']).'</td><td class="right">'.price($row['weighted']).'</td>';
		print '<td class="right">'.(int) $row['nb_won'].'</td><td class="right">'.(int) $row['nb_lost'].'</td></tr>';
	}
	print '</table>';
};
$printDim($langs->trans('CrmByOwner'), $byOwner);
$printDim($langs->trans('CrmBySource'), $bySource);

llxFooter();
$db->close();
