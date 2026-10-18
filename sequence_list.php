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
 * \file    sequence_list.php
 * \ingroup crm
 * \brief   Automated outreach. It stays off until an admin enables them.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/sequence.class.php';
crmStartPage();

$action = GETPOST('action', 'aZ09');
crmRequireToken($action, array());
if ($action === 'add') {
	crmRequireRight('sequence');
	$sequence = new Sequence($db);
	$sequence->label = GETPOST('label', 'alphanohtml');
	$sequence->active = GETPOSTINT('active') ? 1 : 0;
	$newId = $sequence->create($user);
	if ($newId <= 0) {
		setEventMessages(crmErrorText($sequence->error), null, 'errors');
	} else {
		header('Location: '.dol_buildpath('/crm/sequence_card.php', 1).'?id='.$newId);
		exit;
	}
}

$rows = (new Sequence($db))->fetchAll();

llxHeader('', $langs->trans('CrmSequences'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmSequences'), '', 'fa-handshake');
print crmHelp($langs->trans('CrmSequencesIntro'));

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Ref').'</td><td>'.$langs->trans('CrmLabel').'</td><td>'.$langs->trans('CrmActive').'</td><td class="right">'.$langs->trans('CrmSteps').'</td><td class="right">'.$langs->trans('CrmEnrolments').'</td></tr>';
if (count($rows) === 0) {
	print '<tr class="oddeven"><td colspan="5">'.$langs->trans('CrmNoRows').'</td></tr>';
}
foreach ($rows as $row) {
	$url = dol_buildpath('/crm/sequence_card.php', 1).'?id='.(int) $row->rowid;
	print '<tr class="oddeven">';
	print '<td><a href="'.$url.'">'.dol_escape_htmltag((string) $row->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $row->label).'</td>';
	print '<td>'.((int) $row->active ? $langs->trans('Yes') : $langs->trans('No')).'</td>';
	print '<td class="right">'.(int) $row->nb_step.'</td>';
	print '<td class="right">'.(int) $row->nb_enrol.'</td>';
	print '</tr>';
}
print '</table>';

if ($user->hasRight('crm', 'sequence')) {
	print '<h2>'.$langs->trans('CrmNewSequence').'</h2>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input name="label" class="minwidth300" required placeholder="'.dol_escape_htmltag($langs->trans('CrmLabel')).'"> ';
	print '<label><input type="checkbox" name="active" value="1"> '.$langs->trans('CrmActive').'</label> ';
	print '<input type="submit" class="button" value="'.$langs->trans('Create').'">';
	print '</form>';
}

crmHelpToggle();
llxFooter();
$db->close();
