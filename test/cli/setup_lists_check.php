<?php
declare(strict_types=1);

/**
 * \file    test/cli/setup_lists_check.php
 * \ingroup crm
 * \brief   Stages, qualification items, sources and loss reasons: turning off, deleting, codes, and the sequence step
 *          count behind the delete confirmation. Runs in a transaction that is rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/stage.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/sequence.class.php';
require_once __DIR__.'/../../class/qualification.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
$ids = function (array $rows): array {
	return array_map(static fn($row) => (int) $row->rowid, $rows);
};

$db->begin();

// Sources and loss reasons share the same functions.
$id = crmSaveDictionary($db, 'source', 0, 'ZTEST', 'Z test', 99, 1);
crmCliCheck('create a source', $id > 0);
crmCliCheck('a code already used is refused', crmSaveDictionary($db, 'source', 0, 'ZTEST', 'Other', 99, 1) === -2);
crmSetItemActive($db, 'source', $id, 0);
crmCliCheck('an update with active -1 keeps the entry turned off', crmSaveDictionary($db, 'source', $id, 'ZTEST', 'Z test 2', 98, -1) === $id && !in_array($id, $ids(crmFetchDictionary($db, 'source', true)), true));
crmCliCheck('a turned-off source is still in the full list', in_array($id, $ids(crmFetchDictionary($db, 'source', false)), true));
crmCliCheck('an entry keeps its own code on update', crmSaveDictionary($db, 'source', $id, 'ZTEST', 'Z', 1, -1) === $id);
crmCliCheck('an unused source is deleted', crmDeleteItem($db, 'source', $id) === '');

// Qualification.
$qualId = crmSaveQualificationItem($db, 0, 'ZQ', 'ZQ item', 10, 99, 1);
crmCliCheck('create a qualification item', $qualId > 0);
crmCliCheck('a qualification code already used is refused', crmSaveQualificationItem($db, 0, 'ZQ', 'x', 1, 1, 1) === -2);
crmSetItemActive($db, 'qualification', $qualId, 0);
$qual = new Qualification($db);
crmCliCheck('a turned-off item leaves the checklist but stays in the full list', !in_array($qualId, $ids($qual->fetchItems()), true) && in_array($qualId, $ids($qual->fetchItems(false)), true));

// Stages.
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$stage = new Stage($db);
$stage->fk_pipeline = $pipelineId;
$stage->label = '';
crmCliCheck('a stage without a label is refused', $stage->create() < 0 && $stage->error === 'CrmErrorLabelRequired');
$stage->label = 'ZS';
$stage->is_won = 1;
$stage->is_lost = 1;
crmCliCheck('a stage both won and lost is refused', $stage->create() < 0 && $stage->error === 'CrmErrorStageWonAndLost');
$stage->is_won = 0;
$stage->is_lost = 0;
$stageId = $stage->create();
crmCliCheck('create a stage', $stageId > 0);
$activeBefore = count((new Stage($db))->fetchAll($pipelineId, true));
$stage->active = 0;
$stage->update();
crmCliCheck('a turned-off stage is not in the active list', count((new Stage($db))->fetchAll($pipelineId, true)) === $activeBefore - 1);
$stage->active = 1;
$stage->update();

// A deal and the stage it moves through.
$conf->global->CRM_REQUIRE_NEXT_STEP = 0;
$deal = new Opportunity($db);
$deal->label = 'ZDEAL';
$deal->fk_pipeline = $pipelineId;
$deal->fk_user_resp = (int) $user->id;
crmCliCheck('create a deal', $deal->create($user) > 0);
$stage->active = 0;
$stage->update();
crmCliCheck('a move to a turned-off stage is refused', $deal->moveToStage($user, $stageId) < 0 && $deal->error === 'CrmErrorStageInactive');
$stage->active = 1;
$stage->update();
crmCliCheck('a move to an active stage works', $deal->moveToStage($user, $stageId) > 0);
$loaded = new Stage($db);
$loaded->fetch($stageId);
crmCliCheck('a stage that holds a deal is not deleted', $loaded->delete() < 0 && $loaded->error === 'CrmErrorStageNotEmpty');
$first = (new Stage($db))->fetchAll($pipelineId)[0];
$deal->moveToStage($user, (int) $first->id);
$loaded->fetch($stageId);
crmCliCheck('a stage a deal has been in is not deleted', $loaded->delete() < 0 && $loaded->error === 'CrmErrorStageInHistory');

$usedId = crmSaveDictionary($db, 'source', 0, 'ZUSED', 'Z used', 99, 1);
$db->query('UPDATE '.$db->prefix().'crm_opportunity SET fk_source = '.((int) $usedId).' WHERE rowid = '.((int) $deal->id));
crmCliCheck('a source used by a deal is not deleted', crmDeleteItem($db, 'source', $usedId) === 'CrmErrorItemInUse' && crmItemUsage($db, 'source', $usedId) === 1);

// Sequence steps: how many running enrolments wait for a step.
$sequence = new Sequence($db);
$sequence->ref = 'ZSEQ';
$sequence->label = 'Z';
crmCliCheck('create a sequence', $sequence->create($user) > 0);
$step1 = $sequence->addStep('task', 0, 'a', '');
$step2 = $sequence->addStep('task', 1, 'b', '');
crmCliCheck('enrol the deal', $sequence->enrol((int) $deal->id) > 0);
crmCliCheck('one enrolment waits for the first step and none for the second', $sequence->countWaitingOnStep($step1) === 1 && $sequence->countWaitingOnStep($step2) === 0);

crmCliFinish();
