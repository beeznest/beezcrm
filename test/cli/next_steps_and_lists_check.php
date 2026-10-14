<?php
declare(strict_types=1);

/**
 * \file    test/cli/next_steps_and_lists_check.php
 * \ingroup crm
 * \brief   Changing and deleting planned steps, the rules around done steps and other deals, then sorting and paging of
 *          the deal and activity lists. Runs in a transaction that is rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/stage.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';

/** @var DoliDB $db */
/** @var User $user */
$db->begin();

$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deals = array();
foreach (array('ZDEAL', 'ZDEAL2') as $label) {
	$deal = new Opportunity($db);
	$deal->label = $label;
	$deal->fk_pipeline = $pipelineId;
	$deal->fk_user_resp = (int) $user->id;
	$deal->create($user);
	$deals[] = $deal;
}
[$dealA, $dealB] = $deals;
$activity = new CrmActivity($db);
$now = dol_now();
$ownerId = (int) $user->id;

$call = $activity->create($user, (int) $dealA->id, 0, 0, $ownerId, 'call', 'Call A', $now + 86400);
crmCliCheck('create a call', $call > 0);
crmCliCheck('change kind, title and date', $activity->updateStep($user, $call, 'meeting', 'Meet B', $now + 2 * 86400, (int) $dealA->id) === 1);
$row = $activity->fetchPlanned($call, (int) $dealA->id);
crmCliCheck('the change is stored', $row !== null && $row->label === 'Meet B' && $row->code === 'AC_RDV' && abs((int) $db->jdate($row->datep) - ($now + 2 * 86400)) < 2);
crmCliCheck('an empty title keeps the current one', $activity->updateStep($user, $call, 'task', '', $now + 3 * 86400, (int) $dealA->id) === 1 && $activity->fetchPlanned($call, (int) $dealA->id)->label === 'Meet B');
crmCliCheck('a step of another deal is refused', $activity->updateStep($user, $call, 'call', 'x', $now + 86400, (int) $dealB->id) < 0 && $activity->error === 'CrmErrorEventNotFound');
crmCliCheck('an empty date is refused', $activity->updateStep($user, $call, 'call', 'x', 0, (int) $dealA->id) < 0 && $activity->error === 'CrmErrorDateRequired');

$email = $activity->create($user, (int) $dealA->id, 0, 0, $ownerId, 'email', 'Subject [REF]', $now + 86400, 0, 'body');
crmCliCheck('create a planned e-mail', $email > 0);
$changed = $activity->updateStep($user, $email, 'call', 'CHANGED', $now + 5 * 86400, (int) $dealA->id);
$emailRow = $activity->fetchPlanned($email, (int) $dealA->id);
crmCliCheck('a planned e-mail only changes date', $changed === 1 && $emailRow !== null && $emailRow->label === 'Subject [REF]' && $emailRow->event_code === 'AC_EMAIL');

crmCliCheck('complete a step', $activity->complete($user, $call) === 1);
crmCliCheck('a done step cannot be changed', $activity->updateStep($user, $call, 'call', 'x', $now, (int) $dealA->id) < 0 && $activity->error === 'CrmErrorEventDone');
crmCliCheck('a done step cannot be deleted', $activity->deleteStep($user, $call, (int) $dealA->id) < 0 && $activity->error === 'CrmErrorEventDone');
crmCliCheck('a planned step of another deal cannot be deleted', $activity->deleteStep($user, $email, (int) $dealB->id) < 0);
crmCliCheck('delete a planned step', $activity->deleteStep($user, $email, (int) $dealA->id) === 1 && $activity->fetchPlanned($email, (int) $dealA->id) === null);

// Lists.
$activity->create($user, (int) $dealA->id, 0, 0, $ownerId, 'task', 'T', $now + 86400);
$planned = $activity->countList($user, 'planned');
crmCliCheck('the activity list counts and limits', $planned >= 1 && count($activity->fetchList($user, 'planned', 0, 1, 0, 'label', 'desc')) === 1);
$deal = new Opportunity($db);
crmCliCheck('the deal list counts', $deal->countList($user, 'open') >= 2);
$first = $deal->fetchList($user, 'open', 0, 0, '', 1, 0, 'amount', 'asc');
$second = $deal->fetchList($user, 'open', 0, 0, '', 1, 1, 'amount', 'asc');
crmCliCheck('two pages hold different deals', count($first) === 1 && count($second) === 1 && $first[0]->rowid != $second[0]->rowid);
$amounts = array_map(static fn($r) => (float) $r->amount, $deal->fetchList($user, 'open', 0, 0, '', 0, 0, 'amount', 'asc'));
$sorted = $amounts;
sort($sorted);
crmCliCheck('sorted by amount', $amounts === $sorted);
crmCliCheck('an unknown sort column falls back', count($deal->fetchList($user, 'open', 0, 0, '', 2, 0, 'o.ref; DROP TABLE x', 'asc')) === 2);
crmCliCheck('the search count matches', $deal->countList($user, '', 0, 0, 'ZDEAL') === 2);

crmCliFinish();
