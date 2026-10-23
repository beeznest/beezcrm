<?php
declare(strict_types=1);

/**
 * \file    test/cli/mail_contact_match_check.php
 * \ingroup crm
 * \brief   From v1.2.0: a message with no tracking id, thread or reference is recorded on the deal whose contact is the other
 *          end of the exchange (sender of a received message, recipient of a sent one), when that deal is the only open one
 *          of the contact. Rolled back. See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmmailarchive.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;
$conf->global->CRM_MAIL_FROM = 'crm@example.com';
$conf->global->CRM_ARCHIVE_REQUIRE_AUTH = '0';

$db->query('INSERT INTO '.$prefix."societe (nom, entity, datec) VALUES ('ZMATCHSOC', ".$entity.", '".$db->idate(dol_now())."')");
$socId = (int) $db->last_insert_id($prefix.'societe');
$contact = function (string $email) use ($db, $prefix, $entity, $socId, $user): int {
	$db->query('INSERT INTO '.$prefix."socpeople (fk_soc, entity, lastname, email, datec, fk_user_creat) VALUES (".$socId.', '.$entity.", 'ZMATCH', '".$db->escape($email)."', '".$db->idate(dol_now())."', ".((int) $user->id).')');
	return (int) $db->last_insert_id($prefix.'socpeople');
};
$pipelineId = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deal = function (int $contactId, string $label) use ($db, $user, $socId, $pipelineId): Opportunity {
	$d = new Opportunity($db);
	$d->fk_soc = $socId;
	$d->fk_socpeople = $contactId;
	$d->label = $label;
	$d->fk_pipeline = $pipelineId;
	$d->fk_user_resp = (int) $user->id;
	$d->create($user);
	return $d;
};
$archive = new CrmMailArchive($db);
$mail = function (array $over): array {
	static $n = 0;
	$n++;
	return $over + array('msgid' => 'zmatch'.$n.'@mail.example', 'from' => '', 'to' => '', 'cc' => '', 'subject' => 'Hello', 'body' => 'Text', 'date' => dol_now());
};

$a = $contact('Alice.Contact@example.com');
$dealA = $deal($a, 'ZMATCHA');
crmCliCheck('the deal was created', (int) $dealA->id > 0);
crmCliCheck('received: the sender is the deal contact', $archive->findDealByContact(array('alice.contact@example.com')) === (int) $dealA->id);
$r = $archive->record($user, $mail(array('from' => 'Alice <alice.contact@example.com>', 'to' => 'crm@example.com')));
crmCliCheck('received message with no marker is recorded as an incoming e-mail', $r['status'] === 'recorded' && $r['deal'] === (int) $dealA->id);
$type = $db->fetch_object($db->query('SELECT c.code FROM '.$prefix.'actioncomm a INNER JOIN '.$prefix.'c_actioncomm c ON c.id = a.fk_action WHERE a.id = '.((int) $r['event'])));
crmCliCheck('its type is AC_EMAIL_IN', $type && $type->code === 'AC_EMAIL_IN');
$r = $archive->record($user, $mail(array('from' => 'crm@example.com', 'to' => 'Alice <alice.contact@example.com>')));
crmCliCheck('sent: the recipient is the deal contact', $r['status'] === 'recorded' && $r['deal'] === (int) $dealA->id);
$r = $archive->record($user, $mail(array('from' => 'crm@example.com', 'to' => 'other@example.com', 'cc' => 'alice.contact@example.com')));
crmCliCheck('sent: the contact in Cc counts', $r['status'] === 'recorded' && $r['deal'] === (int) $dealA->id);
$r = $archive->record($user, $mail(array('from' => 'stranger@example.net', 'to' => 'crm@example.com')));
crmCliCheck('a stranger is still ignored', $r['status'] === 'ignored');
$r = $archive->record($user, $mail(array('from' => 'crm@example.com', 'to' => 'stranger@example.net')));
crmCliCheck('a message to a stranger is still ignored', $r['status'] === 'ignored');

$dealA2 = $deal($a, 'ZMATCHA2');
crmCliCheck('two open deals for the contact: ambiguous, nothing attached', $archive->findDealByContact(array('alice.contact@example.com')) === 0);
$db->query('UPDATE '.$prefix."crm_opportunity SET status = 'lost' WHERE rowid = ".((int) $dealA2->id));
crmCliCheck('a closed deal does not count', $archive->findDealByContact(array('alice.contact@example.com')) === (int) $dealA->id);
$db->query('UPDATE '.$prefix."crm_opportunity SET status = 'won' WHERE rowid = ".((int) $dealA->id));
crmCliCheck('with no open deal nothing is attached', $archive->findDealByContact(array('alice.contact@example.com')) === 0);
crmCliCheck('our own address is never a contact match', $archive->findDealByContact(array('crm@example.com')) === 0);

$db->rollback();
crmCliFinish();
