<?php
declare(strict_types=1);

/**
 * \file    test/cli/sequence_journal_check.php
 * \ingroup crm
 * \brief   The journal of the sequences keeps the e-mail it made (placeholders replaced), says which step and which day
 *          of the sequence made it, and finds again the content of a line written before it was kept. Dry-run is forced on
 *          in memory, so no e-mail leaves, and everything is rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once __DIR__.'/../../class/stage.class.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';
require_once __DIR__.'/../../class/sequence.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
crmCliCheck('the journal table has its content columns (run the schema step: disable and enable the module)', crmLogContentSupported($db));
if (!crmLogContentSupported($db)) {
	crmCliFinish();
}

$db->begin();
// Nothing may leave: dry-run is on, and the steps are forced to "send" so that they are simulated, not drafted.
$conf->global->CRM_SEQUENCES_ON = '1';
$conf->global->CRM_DRY_RUN = '1';
$conf->global->CRM_SEQUENCE_EMAIL_MODE = 'draft';

$company = new Societe($db);
$company->name = 'ZCustomer';
$company->create($user);
$contact = new Contact($db);
$contact->lastname = 'ZLast';
$contact->firstname = 'ZFirst';
$contact->email = 'zfirst@example.com';
$contact->socid = (int) $company->id;
$contact->create($user);

$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deal = new Opportunity($db);
$deal->label = 'ZDEAL & co';
$deal->amount = 1500;
$deal->fk_pipeline = $pipelineId;
$deal->fk_soc = (int) $company->id;
$deal->fk_socpeople = (int) $contact->id;
$deal->fk_user_resp = (int) $user->id;
crmCliCheck('create a deal', $deal->create($user) > 0);

$sequence = new Sequence($db);
$sequence->ref = 'ZSEQ';
$sequence->label = 'Z sequence';
$sequence->create($user);
$stepText = $sequence->addStep('email', 0, 'Re __CUSTOMER__', 'Hello __CONTACT__, about __DEAL__ (__REF__), amount __AMOUNT__', 'send');
$stepHtml = $sequence->addStep('email', 2, 'Next: __DEAL__', '<p>Hi <b>__CONTACT__</b>, __DEAL__</p>', 'send');
crmCliCheck('enrol the deal', $sequence->enrol((int) $deal->id) > 0);

// The enrolment started three days ago and both steps are due.
$enrolRow = $db->query('SELECT rowid FROM '.$db->prefix().'crm_sequence_enrol WHERE fk_opportunity = '.((int) $deal->id));
$enrolId = (int) $db->fetch_object($enrolRow)->rowid;
$db->query('UPDATE '.$db->prefix()."crm_sequence_enrol SET date_enrol = '".$db->idate(dol_now() - 3 * 86400)."', date_next = '".$db->idate(dol_now() - 3600)."' WHERE rowid = ".$enrolId);

$sequence->runDue($user, 50);
$sequence->runDue($user, 50);
$lines = array_reverse($sequence->fetchLogForOpportunity((int) $deal->id));
crmCliCheck('two steps made two journal lines', count($lines) === 2);
[$first, $second] = array_pad($lines, 2, null);
crmCliCheck('the first line is simulated and keeps the text with the placeholders replaced', $first && $first->status === 'simulated'
	&& $first->subject === 'Re ZCustomer' && strpos((string) $first->body, 'Hello ZFirst ZLast, about ZDEAL & co ('.$deal->ref.')') === 0 && $first->body_format === 'text' && $first->content_source === 'recorded');
crmCliCheck('it keeps the sender and the position of the step', $first && $first->from_email === crmSender() && (int) $first->step_position === 1);
crmCliCheck('an HTML step keeps its HTML and the values are escaped in it', $second && $second->body_format === 'html' && strpos((string) $second->body, 'ZDEAL &amp; co') !== false && (int) $second->step_position === 2);
crmCliCheck('the day of the sequence counts from the enrolment', $first && crmSequenceDay((int) $db->jdate($first->date_enrol), (int) $db->jdate($first->date_send)) === 3);
crmCliCheck('the line names the sequence and the deal', $first && $first->seq_ref === 'ZSEQ' && $first->deal_ref === $deal->ref && (int) $first->fk_opportunity === (int) $deal->id);
crmCliCheck('the journal of the sequence lists the same lines', count($sequence->fetchLog($user)) === 2);

// A line written before the content was kept: rebuilt from the step and saved.
$db->query('UPDATE '.$db->prefix()."crm_sequence_log SET body = '', body_format = '', content_source = '', step_position = NULL WHERE rowid = ".(int) $first->rowid);
$rebuilt = $sequence->ensureLogContent((int) $first->rowid);
crmCliCheck('an old line is rebuilt, saved and marked as rebuilt', $rebuilt && $rebuilt->content_source === 'rebuilt' && strpos((string) $rebuilt->body, 'Hello ZFirst ZLast') === 0 && (int) $rebuilt->step_position === 1);
$db->query('UPDATE '.$db->prefix()."crm_sequence_step SET body = 'changed' WHERE rowid = ".$stepText);
crmCliCheck('once saved it no longer follows the step', strpos((string) $sequence->ensureLogContent((int) $first->rowid)->body, 'Hello ZFirst ZLast') === 0);

// A sent line written before the content was kept: the message recorded on the deal is the real one.
$activity = new CrmActivity($db);
$activity->createEmail($user, (int) $deal->id, (int) $company->id, (int) $contact->id, (int) $user->id, 'out', 'Real subject', 'REAL BODY SENT', dol_now(), array('msgid' => '<zjournal@example.com>', 'from' => 'real@example.com', 'to' => 'zfirst@example.com'));
$db->query('INSERT INTO '.$db->prefix().'crm_sequence_log (entity, fk_enrol, fk_step, date_send, email, subject, status, error_msg) VALUES ('.((int) $conf->entity).', '.$enrolId.', '.$stepHtml.", '".$db->idate(dol_now())."', 'zfirst@example.com', 'Real subject', 'sent', '')");
$oldSentId = (int) $db->last_insert_id($db->prefix().'crm_sequence_log');
$old = $sequence->ensureLogContent($oldSentId);
crmCliCheck('a sent line takes the message recorded on the deal', $old && $old->content_source === 'event' && $old->body === 'REAL BODY SENT' && $old->from_email === 'real@example.com');

// A line whose step is gone has nothing to rebuild.
$db->query('INSERT INTO '.$db->prefix().'crm_sequence_log (entity, fk_enrol, fk_step, date_send, email, subject, status, error_msg) VALUES ('.((int) $conf->entity).', '.$enrolId.", 999999, '".$db->idate(dol_now())."', 'zfirst@example.com', 'Gone', 'simulated', '')");
$goneId = (int) $db->last_insert_id($db->prefix().'crm_sequence_log');
$gone = $sequence->ensureLogContent($goneId);
crmCliCheck('a line whose step is gone stays without content', $gone && (string) $gone->body === '' && (string) $gone->content_source === '');

crmCliCheck('the label of a status is translated', crmLogStatusLabel('simulated') !== '' && crmLogStatusLabel('nonsense') === 'nonsense');
crmCliFinish();
