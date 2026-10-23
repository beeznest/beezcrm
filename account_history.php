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
 * \file    account_history.php
 * \ingroup crm
 * \brief   From v1.2.0. Full CRM history of one third party: the stage moves and the done events of its deals, newest first.
 *          Linked from the "CRM history" block of the third party card.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once __DIR__.'/class/crmaccountview.class.php';
crmStartPage();

$socId = GETPOSTINT('socid');
$societe = new Societe($db);
if ($socId <= 0 || $societe->fetch($socId) <= 0) {
	accessforbidden();
}
if (!$user->hasRight('societe', 'lire') || !empty($user->socid)) {
	accessforbidden();
}

$view = new CrmAccountView($db);
$limit = min(500, GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : (int) $conf->liste_limit);
$total = $view->countTimeline($user, $socId);
$page = max(0, GETPOSTINT('page'));
if ($page * $limit >= $total) {
	$page = 0;
}
$rows = $view->fetchTimeline($user, $socId, $limit, $page * $limit);
$param = '&socid='.$socId.'&limit='.$limit;

llxHeader('', $langs->trans('CrmAccountHistory'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print_barre_liste($langs->trans('CrmAccountHistory').' - '.dol_escape_htmltag($societe->name), $page, $_SERVER['PHP_SELF'], $param, '', '', '', count($rows), $total, 'fa-handshake', 0, '', '', $limit);
print '<p><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.$socId.'">'.$langs->trans('CrmAccountBackToCard').'</a></p>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('CrmDate').'</td><td>'.$langs->trans('CrmOpportunity').'</td><td>'.$langs->trans('CrmAccountHistoryWhat').'</td><td>'.$langs->trans('CrmOwner').'</td></tr>';
print crmAccountTimelineRows($rows);
print '</table>';

llxFooter();
$db->close();
