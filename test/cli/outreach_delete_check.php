<?php
declare(strict_types=1);

/**
 * \file    test/cli/outreach_delete_check.php
 * \ingroup crm
 * \brief   From v1.2.0. An administrator deletes an automated outreach completely, or one enrolment, with the journal: the right,
 *          the counts of the confirmation, what is deleted and what stays; and the links from the history to the text of a
 *          message. Rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/sequence.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';
require_once __DIR__.'/../../class/crmaccountview.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$mkDeal = function (string $label) use ($db, $user, $pipelineId): Opportunity {
	$d = new Opportunity($db);
	$d->label = $label;
	$d->fk_pipeline = $pipelineId;
	$d->fk_user_resp = (int) $user->id;
	$d->create($user);
	return $d;
};
$count = function (string $sql) use ($db): int {
	return (int) $db->fetch_object($db->query($sql))->nb;
};

$sequence = new Sequence($db);
$sequence->ref = 'ZDELSEQ';
$sequence->label = 'Z delete me';
$sequence->create($user);
$sequence->addStep('task', 0, 'a', '');
$sequence->addStep('email', 2, 'b', 'text');
$dealA = $mkDeal('ZDELA');
$dealB = $mkDeal('ZDELB');
$sequence->enrol((int) $dealA->id);
$sequence->enrol((int) $dealB->id);
$enrolIds = array();
$res = $db->query('SELECT rowid FROM '.$prefix.'crm_sequence_enrol WHERE fk_sequence = '.((int) $sequence->id).' ORDER BY rowid');
while ($res && ($o = $db->fetch_object($res))) {
	$enrolIds[] = (int) $o->rowid;
}
foreach ($enrolIds as $enrolId) {
	$db->query('INSERT INTO '.$prefix."crm_sequence_log (entity, fk_enrol, date_send, email, subject, status) VALUES (".$entity.', '.$enrolId.", '".$db->idate(dol_now())."', 'x@example.com', 'S', 'simulated')");
}
crmCliCheck('the outreach has steps, enrolments and journal lines', count($enrolIds) === 2);
$content = $sequence->countContent();
crmCliCheck('the confirmation counts them', $content['steps'] === 2 && $content['enrolments'] === 2 && $content['running'] === 2 && $content['journal'] === 2);

// Rights: only a user who may configure the CRM.
$limited = new User($db);
$limited->id = (int) $user->id + 100000;
$limited->admin = 0;
$limited->rights = new stdClass();
$limited->rights->crm = new stdClass();
$limited->rights->crm->read = 1;
$limited->rights->crm->readall = 1;
$limited->rights->crm->sequence = 1;
crmCliCheck('the right to manage outreach is not enough to delete one', $sequence->deleteCompletely($limited) < 0 && $sequence->error === 'CrmErrorSequenceDeleteRights' && $sequence->deleteEnrolment($limited, $enrolIds[0]) < 0);
crmCliCheck('nothing was deleted', $sequence->countContent()['enrolments'] === 2);

// One enrolment and its journal.
crmCliCheck('an administrator deletes one enrolment', $sequence->deleteEnrolment($user, $enrolIds[0]) === 1
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.$enrolIds[0]) === 0
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_log WHERE fk_enrol = '.$enrolIds[0]) === 0
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.$enrolIds[1]) === 1
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_log WHERE fk_enrol = '.$enrolIds[1]) === 1);
crmCliCheck('an enrolment of another outreach cannot be deleted through this one', $sequence->deleteEnrolment($user, 99999999) < 0);

// A draft made by the outreach is an agenda event: it stays.
$draft = (new CrmActivity($db))->create($user, (int) $dealB->id, 0, 0, (int) $user->id, 'email', 'Draft of the outreach', dol_now() + 3600, 0, 'text');

// The whole outreach.
$sequenceId = (int) $sequence->id;
crmCliCheck('an administrator deletes the outreach completely', $sequence->deleteCompletely($user) === 1
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence WHERE rowid = '.$sequenceId) === 0
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_step WHERE fk_sequence = '.$sequenceId) === 0
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_enrol WHERE fk_sequence = '.$sequenceId) === 0
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_sequence_log WHERE fk_enrol IN ('.implode(',', $enrolIds).')') === 0);
crmCliCheck('the deals and the agenda events stay', $count('SELECT COUNT(*) as nb FROM '.$prefix.'crm_opportunity WHERE rowid IN ('.((int) $dealA->id).','.((int) $dealB->id).')') === 2
	&& $count('SELECT COUNT(*) as nb FROM '.$prefix.'actioncomm WHERE id = '.$draft) === 1);

// The history leads to the text of a message.
$activity = new CrmActivity($db);
$mail = $activity->createEmail($user, (int) $dealA->id, 0, 0, (int) $user->id, 'out', 'Subject of a past e-mail', 'The text', dol_now() - 600, array('msgid' => 'zdel1@host', 'from' => 'crm@example.com', 'to' => 'c@example.com'));
$rows = (new CrmAccountView($db))->fetchDealHistory($user, (int) $dealA->id);
$html = crmAccountTimelineRows($rows, false);
crmCliCheck('a message of the history links to its text on the deal card', $mail > 0 && strpos($html, '#crm-msg-'.$mail) !== false && strpos($html, 'Subject of a past e-mail') !== false);
$withDeal = crmAccountTimelineRows($rows, true);
crmCliCheck('and so does the history of the account', strpos($withDeal, 'opportunity_card.php?id='.((int) $dealA->id).'#crm-msg-'.$mail) !== false);

$db->rollback();
crmCliFinish();
