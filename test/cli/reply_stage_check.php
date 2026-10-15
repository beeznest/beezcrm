<?php
declare(strict_types=1);

/**
 * \file    test/cli/reply_stage_check.php
 * \ingroup crm
 * \brief   The reply stage ("Replied to outreach"): created in a pipeline that lacks one, only one per pipeline, and a deal
 *          moves to it when the prospect replies, only when it is open and before that stage. Rolled back.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/stage.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/sequence.class.php';
require_once __DIR__.'/../../class/crmmailarchive.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the stage table has its is_reply column (run the schema step: disable and enable the module)', crmStageReplySupported($db));
if (!crmStageReplySupported($db)) {
	crmCliFinish();
}
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;

// A pipeline made like an old one: no reply stage.
$pipeline = new Pipeline($db);
$pipelineId = $pipeline->save($user, 0, 'ZREPLY', 'Z reply', 1, 0);
crmCliCheck('create an empty pipeline', $pipelineId > 0);
$add = function (string $label, int $position, int $won = 0, int $lost = 0) use ($db, $pipelineId): int {
	$stage = new Stage($db);
	$stage->fk_pipeline = $pipelineId;
	$stage->label = $label;
	$stage->position = $position;
	$stage->is_won = $won;
	$stage->is_lost = $lost;
	return $stage->create();
};
$new = $add('ZNew', 0);
$qualified = $add('ZQualified', 10);
$negotiation = $add('ZNegotiation', 30);
$won = $add('ZWon', 40, 1);
crmCliCheck('without a reply stage nothing moves', (function () use ($db, $user, $pipelineId, $new) {
	$deal = new Opportunity($db);
	$deal->label = 'ZD0';
	$deal->fk_pipeline = $pipelineId;
	$deal->fk_stage = $new;
	$deal->fk_user_resp = (int) $user->id;
	$deal->create($user);
	return $deal->moveToReplyStage($user) === 0;
})());

// The schema step gives the pipeline its reply stage, after the first open stage, and does nothing the second time.
crmCliCheck('the schema step runs', crmSchemaStep3($db));
$sql = 'SELECT rowid, position, label FROM '.$prefix.'crm_stage WHERE fk_pipeline = '.$pipelineId.' AND is_reply = 1';
$resql = $db->query($sql);
$reply = $resql ? $db->fetch_object($resql) : null;
crmCliCheck('the pipeline got one reply stage between its first two open stages', $reply && $reply->label === 'Replied to outreach' && (int) $reply->position === 5 && $db->num_rows($resql) === 1);
crmSchemaStep3($db);
$count = $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_stage WHERE fk_pipeline = '.$pipelineId.' AND is_reply = 1'))->nb;
crmCliCheck('running it again does not add another', (int) $count === 1);
$replyId = (int) $reply->rowid;

// One reply stage per pipeline; it cannot be closed.
$other = new Stage($db);
$other->fetch($qualified);
$other->is_reply = 1;
$other->update();
$flags = array();
$res = $db->query('SELECT rowid, is_reply FROM '.$prefix.'crm_stage WHERE fk_pipeline = '.$pipelineId.' AND is_reply = 1');
while ($res && ($o = $db->fetch_object($res))) {
	$flags[] = (int) $o->rowid;
}
crmCliCheck('marking another stage moves the mark: one reply stage per pipeline', $flags === array($qualified));
$other->is_reply = 0;
$other->update();
$back = new Stage($db);
$back->fetch($replyId);
$back->is_reply = 1;
$back->update();
$closed = new Stage($db);
$closed->fetch($won);
$closed->is_reply = 1;
crmCliCheck('a won or lost stage cannot be the reply stage', $closed->update() < 0 && $closed->error === 'CrmErrorStageReplyClosed');

// Moves.
$conf->global->CRM_REPLY_MOVES_DEAL = '1';
$conf->global->CRM_REQUIRE_NEXT_STEP = '1';
$make = function (int $stageId, string $label) use ($db, $user, $pipelineId): Opportunity {
	$deal = new Opportunity($db);
	$deal->label = $label;
	$deal->fk_pipeline = $pipelineId;
	$deal->fk_stage = $stageId;
	$deal->fk_user_resp = (int) $user->id;
	$deal->create($user);
	return $deal;
};
$early = $make($new, 'ZEARLY');
crmCliCheck('a deal before the reply stage moves to it, even with no next step planned', $early->moveToReplyStage($user) === 1 && (int) $early->fk_stage === $replyId);
$hist = $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_stage_history WHERE fk_opportunity = '.((int) $early->id).' AND fk_stage_from = '.$new.' AND fk_stage_to = '.$replyId));
crmCliCheck('the move is in the stage history', (int) $hist->nb === 1);
crmCliCheck('a deal already at the reply stage stays', $early->moveToReplyStage($user) === 0);
$late = $make($negotiation, 'ZLATE');
crmCliCheck('a deal beyond the reply stage stays', $late->moveToReplyStage($user) === 0 && (int) $late->fk_stage === $negotiation);
$closedDeal = $make($new, 'ZCLOSED');
$db->query('UPDATE '.$prefix."crm_opportunity SET status = 'lost' WHERE rowid = ".(int) $closedDeal->id);
$closedDeal->status = 'lost';
crmCliCheck('a closed deal stays', $closedDeal->moveToReplyStage($user) === 0);

$conf->global->CRM_REPLY_MOVES_DEAL = '0';
$off = $make($new, 'ZOFF');
crmCliCheck('with the setting off nothing moves', $off->moveToReplyStage($user) === 0 && (int) $off->fk_stage === $new);
$conf->global->CRM_REPLY_MOVES_DEAL = '1';

// "Mark replied" on an enrolment stops it and moves the deal.
$viaSeq = $make($new, 'ZSEQ');
$sequence = new Sequence($db);
$sequence->ref = 'ZREPLYSEQ';
$sequence->label = 'Z';
$sequence->create($user);
$sequence->addStep('task', 0, 'a', '');
$sequence->enrol((int) $viaSeq->id);
$enrolId = (int) $db->fetch_object($db->query('SELECT rowid FROM '.$prefix.'crm_sequence_enrol WHERE fk_opportunity = '.((int) $viaSeq->id)))->rowid;
crmCliCheck('marking the reply stops the enrolment and moves the deal', $sequence->markReplied($user, $enrolId) === 1
	&& $db->fetch_object($db->query('SELECT stop_reason FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.$enrolId))->stop_reason === 'replied'
	&& (int) $db->fetch_object($db->query('SELECT fk_stage FROM '.$prefix.'crm_opportunity WHERE rowid = '.((int) $viaSeq->id)))->fk_stage === $replyId);
crmCliCheck('an enrolment that does not exist is refused', $sequence->markReplied($user, 999999) < 0 && $sequence->error === 'CrmErrorEnrolmentNotFound');

// The collector: an answer from the prospect recorded on the deal moves it; a message we sent does not.
$conf->global->CRM_MAIL_FROM = 'crm@example.com';
$archive = new CrmMailArchive($db);
$answered = $make($new, 'ZANSWERED');
$result = $archive->record($user, array('msgid' => 'zreply-in@example.com', 'from' => 'prospect@example.com', 'to' => 'crm@example.com', 'subject' => 'Re: Hello ['.$answered->ref.']', 'body' => 'Yes, interested', 'date' => dol_now()));
$stageNow = (int) $db->fetch_object($db->query('SELECT fk_stage FROM '.$prefix.'crm_opportunity WHERE rowid = '.((int) $answered->id)))->fk_stage;
crmCliCheck('an answer recorded by the collector moves the deal', $result['status'] === 'recorded' && $stageNow === $replyId);
$sentOnly = $make($new, 'ZSENTONLY');
$result = $archive->record($user, array('msgid' => 'zreply-out@example.com', 'from' => 'crm@example.com', 'to' => 'prospect@example.com', 'subject' => 'Hello ['.$sentOnly->ref.']', 'body' => 'Our message', 'date' => dol_now()));
$stageNow = (int) $db->fetch_object($db->query('SELECT fk_stage FROM '.$prefix.'crm_opportunity WHERE rowid = '.((int) $sentOnly->id)))->fk_stage;
crmCliCheck('a message we sent does not move the deal', $result['status'] === 'recorded' && $stageNow === $new);

// Copying a pipeline copies the mark.
$copy = new Pipeline($db);
$copyId = $copy->save($user, 0, 'ZREPLYCOPY', 'Z copy', 1, $pipelineId);
$copied = $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix.'crm_stage WHERE fk_pipeline = '.$copyId.' AND is_reply = 1'));
crmCliCheck('a copied pipeline keeps its reply stage', (int) $copied->nb === 1);

crmCliFinish();
