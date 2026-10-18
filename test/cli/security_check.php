<?php
declare(strict_types=1);

/**
 * \file    test/cli/security_check.php
 * \ingroup crm
 * \brief   Security rules found by the OWASP review: visibility of outreach enrolments and journal, enrolment rules,
 *          validation of the links of a deal (third party, contact, proposal), reopening a closed deal, settings and stage
 *          validation, error text shown to users, trust rules of the e-mail archive. Rolled back.
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
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;

/** A user who may read and write his own deals and run outreach, but not see other people's deals. */
$makeUser = static function (int $id, bool $readall) use ($db): User {
	$u = new User($db);
	$u->id = $id;
	$u->admin = 0;
	$u->email = 'colleague@example.com';
	$u->rights = new stdClass();
	$u->rights->crm = new stdClass();
	foreach (array('read', 'write', 'sequence', 'activity') as $right) {
		$u->rights->crm->$right = 1;
	}
	if ($readall) {
		$u->rights->crm->readall = 1;
	}
	$u->rights->societe = new stdClass();
	$u->rights->societe->lire = 1;
	$u->all_permissions_are_loaded = 1;
	return $u;
};
$stranger = $makeUser(987654, false);

$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$db->query('INSERT INTO '.$prefix."societe (nom, entity, email, datec) VALUES ('ZSECSOC', ".$entity.", 'buyer@acme-zsec.example', '".$db->idate(dol_now())."')");
$socId = (int) $db->last_insert_id($prefix.'societe');
$db->query('INSERT INTO '.$prefix."societe (nom, entity, datec) VALUES ('ZSECOTHER', ".$entity.", '".$db->idate(dol_now())."')");
$otherSocId = (int) $db->last_insert_id($prefix.'societe');
$db->query('INSERT INTO '.$prefix."socpeople (fk_soc, entity, lastname, email, datec) VALUES (".$otherSocId.', '.$entity.", 'ZOther', 'other@elsewhere-zsec.example', '".$db->idate(dol_now())."')");
$otherContactId = (int) $db->last_insert_id($prefix.'socpeople');

$deal = new Opportunity($db);
$deal->label = 'ZSEC '."\n<b>bold</b>";
$deal->fk_pipeline = $pipelineId;
$deal->fk_soc = $socId;
$deal->fk_user_resp = (int) $user->id;
$deal->create($user);
$dealId = (int) $deal->id;
crmCliCheck('a deal label is one line without markup', $deal->label === 'ZSEC bold');

// Links of a deal.
$bad = new Opportunity($db);
$bad->label = 'ZBAD';
$bad->fk_pipeline = $pipelineId;
$bad->fk_user_resp = (int) $user->id;
$bad->fk_soc = 999999;
crmCliCheck('a third party that does not exist is refused', $bad->create($user) < 0 && $bad->error === 'CrmErrorThirdPartyInvalid');
$bad->fk_soc = $socId;
$bad->fk_socpeople = $otherContactId;
crmCliCheck('a contact of another third party is refused', $bad->create($user) < 0 && $bad->error === 'CrmErrorContactInvalid');
$bad->fk_socpeople = 0;
$noRead = $makeUser(987655, true);
$noRead->rights->societe->lire = 0;
crmCliCheck('a user who may not read third parties cannot link one', $bad->create($noRead) < 0 && $bad->error === 'CrmErrorThirdPartyInvalid');
crmCliCheck('a proposal that does not exist is refused', $deal->linkProposal($user, 999999) < 0 && $deal->error === 'CrmErrorProposalInvalid');

// Outreach: the rules are in enrol().
$sequence = new Sequence($db);
$sequence->ref = 'ZSECSEQ';
$sequence->label = 'Z sec';
$sequence->create($user);
$sequence->addStep('task', 0, 'First', '');
crmCliCheck('a deal that does not exist cannot be enrolled', $sequence->enrol(999999) < 0 && $sequence->error === 'CrmErrorNotFound');
$enrolId = $sequence->enrol($dealId);
crmCliCheck('an open deal is enrolled', $enrolId > 0);
crmCliCheck('enrol() itself refuses a deal that already follows an outreach', $sequence->enrol($dealId) < 0 && $sequence->error === 'CrmErrorDealRunning');
$sequence->active = 0;
$inactive = new Sequence($db);
$inactive->fetch((int) $sequence->id);
$inactive->active = 0;
crmCliCheck('enrol() itself refuses an inactive outreach', $inactive->enrol($dealId) < 0 && $inactive->error === 'CrmErrorSequenceInactive');

// Who sees what.
crmCliCheck('the owner sees the enrolment', $sequence->enrolmentVisible($user, $enrolId, (int) $sequence->id));
crmCliCheck('another user without readall does not', !$sequence->enrolmentVisible($stranger, $enrolId, (int) $sequence->id));
crmCliCheck('a user with readall does', $sequence->enrolmentVisible($makeUser(987656, true), $enrolId));
crmCliCheck('the enrolment does not belong to another outreach', !$sequence->enrolmentVisible($user, $enrolId, (int) $sequence->id + 100000));
crmCliCheck('the list of enrolments hides it from the other user', count($sequence->fetchEnrolments($stranger)) === 0 && count($sequence->fetchEnrolments($user)) === 1);
crmCliCheck('the journal is filtered the same way', count($sequence->fetchLog($stranger)) === 0);
crmCliCheck('marking a reply on a deal one cannot see is refused', $sequence->markReplied($stranger, $enrolId) < 0 && $sequence->error === 'CrmErrorEnrolmentNotFound');
$stillRunning = $db->fetch_object($db->query('SELECT status FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.$enrolId))->status;
crmCliCheck('and it did not stop the enrolment', $stillRunning === 'running');

// Reopening a closed deal needs a next step like any other move.
$conf->global->CRM_REQUIRE_NEXT_STEP = '1';
$stages = (new Stage($db))->fetchAll($pipelineId, true);
$won = null;
$open = null;
foreach ($stages as $stage) {
	if ($stage->is_won && $won === null) {
		$won = $stage;
	}
	if (!$stage->isClosed() && $open === null && !$stage->is_reply) {
		$open = $stage;
	}
}
if ($won !== null && $open !== null) {
	$closed = new Opportunity($db);
	$closed->label = 'ZREOPEN';
	$closed->fk_pipeline = $pipelineId;
	$closed->fk_user_resp = (int) $user->id;
	$closed->create($user);
	$closed->moveToStage($user, (int) $won->id);
	crmCliCheck('reopening a won deal without a next step is refused', $closed->moveToStage($user, (int) $open->id) < 0 && $closed->error === 'CrmErrorNextStepRequired');
}

// Settings.
crmCliCheck('a switch is 0 or 1', crmNormalizeSetting('CRM_DRY_RUN', '1') === '1' && crmNormalizeSetting('CRM_DRY_RUN', '2') === null);
crmCliCheck('a number stays in its range', crmNormalizeSetting('CRM_MAX_EMAILS_PER_RUN', '50') === '50' && crmNormalizeSetting('CRM_MAX_EMAILS_PER_RUN', '99999') === null && crmNormalizeSetting('CRM_MAX_EMAILS_PER_RUN', '-1') === null);
crmCliCheck('a sender address is one address on one line', crmNormalizeSetting('CRM_MAIL_FROM', 'crm@example.com') === 'crm@example.com'
	&& crmNormalizeSetting('CRM_MAIL_FROM', "a@example.com\r\nBcc: x@example.com") === null && crmNormalizeSetting('CRM_MAIL_FROM', 'a@example.com, b@example.com') === null);
crmCliCheck('an unknown setting is refused', crmNormalizeSetting('MAIN_SOMETHING', '1') === null);

// Stage values.
$stage = new Stage($db);
$stage->fk_pipeline = $pipelineId;
$stage->label = 'ZSEC stage';
$stage->color = 'red;"><script>';
crmCliCheck('a stage colour must be #rrggbb', $stage->create() < 0 && $stage->error === 'CrmErrorStageColorInvalid');
$stage->color = '#112233';
$stage->probability = 500;
crmCliCheck('a probability is clamped to 100', $stage->create() > 0 && (int) $stage->probability === 100);

// Error text.
$admin = $makeUser(987657, true);
$admin->admin = 1;
$GLOBALS['user'] = $stranger;
$text = crmErrorText('SQL error <script>alert(1)</script> near table llx_x');
crmCliCheck('a raw error is not shown to an ordinary user', strpos($text, 'llx_x') === false && strpos($text, '<script') === false);
$GLOBALS['user'] = $admin;
$text = crmErrorText('SQL error <script>alert(1)</script>');
crmCliCheck('an administrator sees it, escaped', strpos($text, '<script') === false && strpos($text, 'SQL error') !== false);
$GLOBALS['user'] = $user;
crmCliCheck('a database error is a language key', crmDbError($db) === 'CrmErrorDatabase');

// E-mail archive: only mail that comes from, or goes to, an address the deal knows is kept.
$conf->global->CRM_MAIL_FROM = 'crm@example.com';
$conf->global->CRM_ARCHIVE_REQUIRE_AUTH = '0';
$archive = new CrmMailArchive($db);
$ref = $deal->ref;
$mail = static function (array $extra) use ($ref): array {
	return array_merge(array('msgid' => 'zsec-'.mt_rand().'@x.example', 'from' => 'buyer@acme-zsec.example', 'to' => 'crm@example.com', 'subject' => 'Re: Hello ['.$ref.']', 'body' => 'Yes', 'date' => dol_now()), $extra);
};
crmCliCheck('a stranger who knows the reference cannot attach a message', $archive->record($user, $mail(array('from' => 'attacker@evil.example')))['status'] === 'ignored');
crmCliCheck('nor with a forged internal sender and no known recipient', $archive->record($user, $mail(array('from' => 'crm@example.com', 'to' => 'attacker@evil.example')))['status'] === 'ignored');
crmCliCheck('a message that fails the authentication of our server is ignored', $archive->record($user, $mail(array('authresults' => 'mx.example; spf=fail')))['status'] === 'ignored');
$conf->global->CRM_ARCHIVE_REQUIRE_AUTH = '1';
crmCliCheck('with authentication required, a message without proof is ignored', $archive->record($user, $mail(array()))['status'] === 'ignored');
crmCliCheck('and one that passed is kept', $archive->record($user, $mail(array('authresults' => 'mx.example; spf=pass')))['status'] === 'recorded');
$conf->global->CRM_ARCHIVE_REQUIRE_AUTH = '0';
$enrolId2 = $db->fetch_object($db->query('SELECT status FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.$enrolId))->status;
crmCliCheck('a trusted answer stops the outreach', $enrolId2 === 'stopped');
$sequence2 = new Sequence($db);
$sequence2->fetch((int) $sequence->id);
$again = $sequence2->enrol($dealId);
crmCliCheck('the deal can follow an outreach again', $again > 0);
$result = $archive->record($user, $mail(array('subject' => 'Out of office ['.$ref.']', 'auto' => true)));
$status = $db->fetch_object($db->query('SELECT status FROM '.$prefix.'crm_sequence_enrol WHERE rowid = '.$again))->status;
crmCliCheck('an automatic reply is kept but does not stop the outreach', $result['status'] === 'recorded' && $status === 'running');
$result = $archive->record($user, $mail(array('subject' => 'Colleague ['.$ref.']', 'from' => 'sales@acme-zsec.example')));
crmCliCheck('a colleague of the contact at the same company domain is trusted', $result['status'] === 'recorded');
$result = $archive->record($user, $mail(array('date' => dol_now() + 5 * 365 * 86400)));
$event = $db->fetch_object($db->query('SELECT datep FROM '.$prefix.'actioncomm WHERE id = '.((int) $result['event'])));
crmCliCheck('a date in the future is replaced by now', $result['status'] === 'recorded' && $event && $db->jdate($event->datep) <= dol_now() + 5);

crmCliFinish();
