<?php
declare(strict_types=1);

/**
 * \file    test/cli/account_view_check.php
 * \ingroup crm
 * \brief   From v1.2.0. The CRM on the third party card: deals of the third party, the timeline of stage moves and done events
 *          (the "CRM history" block and its full page), and the two hooks of the card. Rolled back.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';
require_once __DIR__.'/../../class/crmaccountview.class.php';
require_once __DIR__.'/../../class/actions_crm.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;
$conf->global->CRM_REQUIRE_NEXT_STEP = '0';
$mkSoc = function (string $name, int $client) use ($db, $prefix, $entity): int {
	$db->query('INSERT INTO '.$prefix."societe (nom, entity, client, datec) VALUES ('".$db->escape($name)."', ".$entity.', '.$client.", '".$db->idate(dol_now())."')");
	return (int) $db->last_insert_id($prefix.'societe');
};
$socId = $mkSoc('ZVIEWSOC', 2);
$otherSoc = $mkSoc('ZVIEWOTHER', 2);
$emptySoc = $mkSoc('ZVIEWEMPTY', 2);
$pipeline = (new Pipeline($db))->fetchAll(true)[0];
$mkDeal = function (int $soc, string $label) use ($db, $user, $pipeline): Opportunity {
	$d = new Opportunity($db);
	$d->fk_soc = $soc;
	$d->label = $label;
	$d->fk_pipeline = (int) $pipeline->id;
	$d->fk_user_resp = (int) $user->id;
	$d->create($user);
	return $d;
};
$deal = $mkDeal($socId, 'ZVIEWDEAL');
$deal2 = $mkDeal($socId, 'ZVIEWDEAL2');
$foreign = $mkDeal($otherSoc, 'ZVIEWFOREIGN');
crmCliCheck('deals were created', (int) $deal->id > 0 && (int) $deal2->id > 0 && (int) $foreign->id > 0);

$view = new CrmAccountView($db);
$deals = $view->fetchDeals($user, $socId);
crmCliCheck('both deals of the third party, not the other one', count($deals) === 2);
$db->query('UPDATE '.$prefix."crm_opportunity SET status = 'lost' WHERE rowid = ".((int) $deal->id));
$deals = $view->fetchDeals($user, $socId);
crmCliCheck('the open deal comes first', (int) $deals[0]->rowid === (int) $deal2->id);
crmCliCheck('no deal for a third party that has none', count($view->fetchDeals($user, $emptySoc)) === 0);

// Timeline: stage moves (history rows) and done events; planned events and other accounts do not show.
$history = $db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_stage_history WHERE fk_opportunity IN ('.((int) $deal->id).','.((int) $deal2->id).')');
$base = (int) $db->fetch_object($history)->nb;
$activity = new CrmActivity($db);
$done = [];
for ($i = 0; $i < 6; $i++) {
	$id = $activity->create($user, (int) $deal2->id, $socId, 0, (int) $user->id, 'call', 'ZCALL'.$i, dol_now() - 3600 * (10 - $i), 100);
	$done[] = $id;
}
$planned = $activity->create($user, (int) $deal2->id, $socId, 0, (int) $user->id, 'call', 'ZPLANNED', dol_now() + 86400, 0);
$activity->create($user, (int) $foreign->id, $otherSoc, 0, (int) $user->id, 'call', 'ZFOREIGNCALL', dol_now() - 60, 100);
crmCliCheck('events were created', $done[0] > 0 && $planned > 0);
crmCliCheck('the timeline counts stage rows and done events of this third party only', $view->countTimeline($user, $socId) === $base + 6);
$rows = $view->fetchTimeline($user, $socId, 5);
crmCliCheck('five lines, newest first', count($rows) === 5 && strcmp((string) $rows[0]->dt, (string) $rows[4]->dt) >= 0 && strcmp((string) $rows[1]->dt, (string) $rows[2]->dt) >= 0);
$labels = array_map(function ($r) {
	return (string) $r->event_label;
}, $view->fetchTimeline($user, $socId, 50));
crmCliCheck('a planned event and another account never show', !in_array('ZPLANNED', $labels, true) && !in_array('ZFOREIGNCALL', $labels, true));
crmCliCheck('the second page starts after the first', $view->fetchTimeline($user, $socId, 5, 5) !== array() && $view->fetchTimeline($user, $socId, 5, 5)[0]->id !== $rows[0]->id);
$html = crmAccountTimelineRows($rows);
crmCliCheck('rows link to the deal card', strpos($html, 'opportunity_card.php?id='.((int) $deal2->id)) !== false && strpos($html, 'ZCALL5') !== false);

// A user who may only read their own deals does not see the deals of someone else.
$db->query('UPDATE '.$prefix.'crm_opportunity SET fk_user_resp = 0 WHERE rowid IN ('.((int) $deal->id).','.((int) $deal2->id).')');
$limited = new User($db);
$limited->id = (int) $user->id;
$limited->admin = 0;
$limited->rights = new stdClass();
$limited->rights->crm = new stdClass();
$limited->rights->crm->read = 1;
crmCliCheck('the limited user has "read" and not "read all"', $limited->hasRight('crm', 'read') && !$limited->hasRight('crm', 'readall'));
crmCliCheck('without "read all", deals of another owner and their history are hidden', count($view->fetchDeals($limited, $socId)) === 0 && $view->countTimeline($limited, $socId) === 0);
$db->query('UPDATE '.$prefix.'crm_opportunity SET fk_user_resp = '.((int) $user->id).' WHERE rowid IN ('.((int) $deal->id).','.((int) $deal2->id).')');

// The hooks.
$hook = new ActionsCrm($db);
$thirdparty = new Societe($db);
$thirdparty->fetch($socId);
$action = '';
$parameters = array('currentcontext' => 'thirdpartycomm');
$hook->resprints = '';
$hook->formObjectOptions($parameters, $thirdparty, $action, null);
crmCliCheck('the card row links to the open deal', strpos($hook->resprints, 'opportunity_card.php?id='.((int) $deal2->id)) !== false && strpos($hook->resprints, 'ZVIEWDEAL2') !== false);
$edit = 'edit';
$hook->resprints = '';
$hook->formObjectOptions($parameters, $thirdparty, $edit, null);
crmCliCheck('no row in edit mode', $hook->resprints === '');
$hook->resprints = '';
$hook->formObjectOptions(array('currentcontext' => 'thirdpartycard'), $thirdparty, $action, null);
crmCliCheck('no row in another context (the Third party tab)', $hook->resprints === '');
$empty = new Societe($db);
$empty->fetch($emptySoc);
$empty->prospect = 1;
$hook->resprints = '';
$hook->formObjectOptions($parameters, $empty, $action, null);
crmCliCheck('a prospect with no deal gets a link to create one', strpos($hook->resprints, 'action=create&amp;socid='.$emptySoc) !== false || strpos($hook->resprints, 'action=create&socid='.$emptySoc) !== false);
$hook->resprints = '';
$hook->addMoreRecentObjects($parameters, $thirdparty, $action, null);
$out = $hook->resprints;
$hook->resprints = '';
$hook->addMoreRecentObjects(array('currentcontext' => 'thirdpartycard'), $thirdparty, $action, null);
crmCliCheck('no history block on the Third party tab', $hook->resprints === '');
crmCliCheck('the history block shows five lines and links to the full page', substr_count($out, '<tr class="oddeven">') === 5 && strpos($out, 'account_history.php?socid='.$socId) !== false);

$db->rollback();
crmCliFinish();
