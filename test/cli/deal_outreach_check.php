<?php
declare(strict_types=1);

/**
 * \file    test/cli/deal_outreach_check.php
 * \ingroup crm
 * \brief   Enrolling a deal in an automated outreach from its card: the rules (open deal, active outreach with a step, one
 *          outreach at a time) and the moment the enrolment starts. Rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/stage.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/sequence.class.php';

/** @var DoliDB $db */
/** @var User $user */
$db->begin();
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deal = new Opportunity($db);
$deal->label = 'ZOUT';
$deal->fk_pipeline = $pipelineId;
$deal->fk_user_resp = (int) $user->id;
$deal->create($user);
$dealId = (int) $deal->id;

$empty = new Sequence($db);
$empty->ref = 'ZEMPTY';
$empty->label = 'Z empty';
$empty->create($user);
$full = new Sequence($db);
$full->ref = 'ZFULL';
$full->label = 'Z full';
$full->create($user);
$full->addStep('task', 3, 'First', '');
$full->addStep('task', 5, 'Second', '');
$off = new Sequence($db);
$off->ref = 'ZOFF';
$off->label = 'Z off';
$off->active = 0;
$off->create($user);
$off->addStep('task', 0, 'x', '');

$model = new Sequence($db);
$offered = array_map(static fn($row) => $row->ref, $model->fetchEnrollable());
crmCliCheck('only an active outreach with a step is offered', in_array('ZFULL', $offered, true) && !in_array('ZEMPTY', $offered, true) && !in_array('ZOFF', $offered, true));
crmCliCheck('a deal that follows nothing has no enrolment', count($model->fetchEnrolmentsForOpportunity($dealId)) === 0 && !$model->hasRunningEnrolment($dealId));

$fetched = new Sequence($db);
$fetched->fetch((int) $off->id);
crmCliCheck('an inactive outreach is refused', $fetched->enrolFromDeal($dealId) < 0 && $fetched->error === 'CrmErrorSequenceInactive');
$fetched->fetch((int) $empty->id);
crmCliCheck('an outreach without a step is refused', $fetched->enrolFromDeal($dealId) < 0 && $fetched->error === 'CrmErrorSequenceNoSteps');

$before = dol_now();
$fetched->fetch((int) $full->id);
$enrolId = $fetched->enrolFromDeal($dealId);
$after = dol_now();
crmCliCheck('an open deal is enrolled', $enrolId > 0);
$rows = $model->fetchEnrolmentsForOpportunity($dealId);
$row = $rows[0] ?? null;
crmCliCheck('the enrolment starts at the moment of the save', $row && (int) $db->jdate($row->date_enrol) >= $before && (int) $db->jdate($row->date_enrol) <= $after);
crmCliCheck('the first step is due after its delay counted from that moment', $row && abs((int) $db->jdate($row->date_next) - ((int) $db->jdate($row->date_enrol) + 3 * 86400)) < 2 && (int) $row->current_step === 1);
crmCliCheck('the row names the outreach and the step it waits for', $row && $row->seq_ref === 'ZFULL' && $row->step_subject === 'First' && (string) $row->status === 'running');
crmCliCheck('the deal now follows an outreach', $model->hasRunningEnrolment($dealId));

$fetched->fetch((int) $full->id);
crmCliCheck('a deal that follows one cannot be enrolled in the same one again', $fetched->enrolFromDeal($dealId) < 0 && $fetched->error === 'CrmErrorDealRunning');
$other = new Sequence($db);
$other->ref = 'ZOTHER';
$other->label = 'Z other';
$other->create($user);
$other->addStep('task', 0, 'y', '');
crmCliCheck('nor in another one', $other->enrolFromDeal($dealId) < 0 && $other->error === 'CrmErrorDealRunning');

$db->query('UPDATE '.$db->prefix()."crm_sequence_enrol SET status = 'stopped', stop_reason = 'replied' WHERE rowid = ".$enrolId);
crmCliCheck('once stopped the deal can follow another outreach', $other->enrolFromDeal($dealId) > 0 && count($model->fetchEnrolmentsForOpportunity($dealId)) === 2);
crmCliCheck('the status is shown with its reason', crmEnrolStatusLabel('stopped', 'replied') !== 'stopped' && strpos(crmEnrolStatusLabel('stopped', 'replied'), '(') !== false && crmEnrolStatusLabel('weird', '') === 'weird');

$closed = new Opportunity($db);
$closed->label = 'ZCLOSED';
$closed->fk_pipeline = $pipelineId;
$closed->fk_user_resp = (int) $user->id;
$closed->create($user);
$db->query('UPDATE '.$db->prefix()."crm_opportunity SET status = 'won' WHERE rowid = ".(int) $closed->id);
$fetched->fetch((int) $full->id);
crmCliCheck('a closed deal is refused', $fetched->enrolFromDeal((int) $closed->id) < 0 && $fetched->error === 'CrmErrorDealClosed');

crmCliFinish();
