<?php
declare(strict_types=1);

/**
 * \file    test/cli/messages_check.php
 * \ingroup crm
 * \brief   From v1.2.0. The message history of a deal (every e-mail sent or received, with its text) and the short marker "[O...]"
 *          of a message written in a mail client, found by the collector like the old "[OPP...]". Rolled back.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';
require_once __DIR__.'/../../class/pipeline.class.php';
require_once __DIR__.'/../../class/opportunity.class.php';
require_once __DIR__.'/../../class/crmactivity.class.php';
require_once __DIR__.'/../../class/crmmailarchive.class.php';
require_once __DIR__.'/../../class/crmaccountview.class.php';
require_once __DIR__.'/../../class/crmemaildraft.class.php';

global $langs;
/** @var DoliDB $db */
/** @var Conf $conf */
/** @var User $user */
$db->begin();
$prefix = $db->prefix();
$entity = (int) $conf->entity;
$conf->global->CRM_MAIL_FROM = 'crm@example.com';
$conf->global->CRM_ARCHIVE_REQUIRE_AUTH = '0';

$db->query('INSERT INTO '.$prefix."societe (nom, entity, datec) VALUES ('ZMSGSOC', ".$entity.", '".$db->idate(dol_now())."')");
$socId = (int) $db->last_insert_id($prefix.'societe');
$db->query('INSERT INTO '.$prefix."socpeople (fk_soc, entity, lastname, email, datec, fk_user_creat) VALUES (".$socId.', '.$entity.", 'ZMSG', 'zmsg.contact@example.com', '".$db->idate(dol_now())."', ".((int) $user->id).')');
$contactId = (int) $db->last_insert_id($prefix.'socpeople');
$deal = new Opportunity($db);
$deal->fk_soc = $socId;
$deal->fk_socpeople = $contactId;
$deal->label = 'ZMSGDEAL';
$deal->fk_pipeline = (int) (new Pipeline($db))->fetchAll(true)[0]->id;
$deal->fk_user_resp = (int) $user->id;
$deal->create($user);
$dealId = (int) $deal->id;
$ref = (string) $deal->ref;
$marker = crmDealMarker($ref);
crmCliCheck('the marker of the reference is short', $marker !== $ref && strpos($ref, 'OPP') === 0 && $marker === 'O'.substr($ref, 3));

$activity = new CrmActivity($db);
$archive = new CrmMailArchive($db);
$sent = $activity->createEmail($user, $dealId, $socId, $contactId, (int) $user->id, 'out', 'Hello from the platform', "First message\nline two", dol_now() - 7200, array('msgid' => 'zmsgsent1@host', 'from' => 'crm@example.com', 'to' => 'zmsg.contact@example.com'));
crmCliCheck('a message sent by the platform is recorded', $sent > 0);
$draft = $activity->create($user, $dealId, $socId, $contactId, (int) $user->id, 'email', 'Draft subject', dol_now() + 3600, 0, 'not sent');
crmCliCheck('a draft is created', $draft > 0);
$r = $archive->record($user, array('msgid' => 'zmsgin1@mail.example', 'from' => 'ZMSG <zmsg.contact@example.com>', 'to' => 'crm@example.com', 'subject' => 'Re: Hello from the platform ['.$marker.']', 'body' => '<p>Thank <b>you</b></p>', 'date' => dol_now() - 3600));
crmCliCheck('an answer with the short marker in its subject is found by the collector', $r['status'] === 'recorded' && $r['deal'] === $dealId);
$r2 = $archive->record($user, array('msgid' => 'zmsgin2@mail.example', 'from' => 'zmsg.contact@example.com', 'to' => 'crm@example.com', 'subject' => 'Re: Hello from the platform ['.$ref.']', 'body' => 'Old marker still works', 'date' => dol_now() - 1800));
crmCliCheck('the old marker [OPP...] is still found', $r2['status'] === 'recorded' && $r2['deal'] === $dealId);
$r3 = $archive->record($user, array('msgid' => 'zmsgin3@mail.example', 'from' => 'zmsg.contact@example.com', 'to' => 'crm@example.com', 'subject' => 'No marker at all', 'body' => 'By the contact address', 'date' => dol_now() - 900));
crmCliCheck('a message with no marker is found by the address of the contact', $r3['status'] === 'recorded' && $r3['deal'] === $dealId);
crmCliCheck('the short marker alone finds the deal (no address involved)', $archive->findDeal(array(), 'Re: x ['.$marker.']') === $dealId && $archive->findDeal(array(), 'Re: x ['.$ref.']') === $dealId);
crmCliCheck('a marker of no deal is ignored', $archive->findDeal(array(), 'Re: x [O1999-9999]') === 0);

$messages = $activity->fetchMessages($dealId);
crmCliCheck('the message history lists the sent and the received messages, not a draft still planned', count($messages) === 4 && !in_array($draft, array_map(function ($m) {
	return (int) $m->rowid;
}, $messages), true));
crmCliCheck('none of them is flagged as a closed draft', array_sum(array_map(function ($m) {
	return (int) $m->closed_draft;
}, $messages)) === 0);
// A draft only marked as done is not a message that was sent.
crmCliCheck('a draft is marked as done', $activity->complete($user, $draft, $dealId) > 0);
$messages = $activity->fetchMessages($dealId);
$closed = array_values(array_filter($messages, function ($m) use ($draft) {
	return (int) $m->rowid === $draft;
}));
crmCliCheck('a closed draft is listed, flagged as a closed draft, with its text', count($closed) === 1 && (int) $closed[0]->closed_draft === 1 && $closed[0]->note === 'not sent');
crmCliCheck('the sent and received messages are not flagged', count(array_filter($messages, function ($m) {
	return (int) $m->closed_draft === 0;
})) === 4);
$rows = (new CrmAccountView($db))->fetchDealHistory($user, $dealId);
$closedRows = array_values(array_filter($rows, function ($r) use ($draft) {
	return $r->kind === 'event' && (int) $r->id === $draft;
}));
crmCliCheck('the history labels it a closed draft, not an e-mail', count($closedRows) === 1 && (int) $closedRows[0]->closed_draft === 1 && strpos(crmAccountTimelineRows($closedRows, false), $langs->trans('CrmDraftClosed')) !== false);
crmCliCheck('oldest first, with the direction', $messages[0]->code === 'AC_EMAIL' && $messages[1]->code === 'AC_EMAIL_IN' && $messages[3]->code === 'AC_EMAIL_IN');
crmCliCheck('each message has its subject, addresses and text', $messages[0]->email_subject === 'Hello from the platform' && $messages[0]->email_to === 'zmsg.contact@example.com' && $messages[0]->note === "First message\nline two" && strpos((string) $messages[1]->email_from, 'zmsg.contact@example.com') !== false);
crmCliCheck('the text of an HTML message can be read as text', crmHtmlToText((string) $messages[1]->note) === 'Thank you' || strpos(crmHtmlToText((string) $messages[1]->note), 'Thank you') !== false);

// Nothing adds a deal marker to the title of a draft, and nothing takes one out (what the user types stays); a copy of a message sent
// from a mail client that carries the marker still replaces a draft that has none.
$draftB = $activity->create($user, $dealId, $socId, $contactId, (int) $user->id, 'email', 'Offer follow-up', dol_now() + 5400, 0, 'planned text');
$cd = new CrmEmailDraft($db);
$cd->fetch($draftB);
crmCliCheck('the subject of a draft is its title, with no marker added', $cd->subject() === 'Offer follow-up');
crmCliCheck('saving a draft keeps the title as typed, a typed marker included', $cd->save($user, 'Offer follow-up v2 ['.$marker.']', 'planned text 2') === 1 && $db->fetch_object($db->query('SELECT label FROM '.$prefix.'actioncomm WHERE id = '.$draftB))->label === 'Offer follow-up v2 ['.$marker.']');
$cd->save($user, 'Offer follow-up v2', 'planned text 2');
$before = (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix."actioncomm WHERE elementtype = 'opportunity@crm' AND fk_element = ".$dealId." AND code = 'AC_EMAIL'"))->nb;
$rp = $archive->record($user, array('msgid' => 'zmsgmailto1@mail.example', 'from' => 'crm@example.com', 'to' => 'zmsg.contact@example.com', 'subject' => 'Offer follow-up v2 ['.$marker.']', 'body' => 'Sent from a mail client', 'date' => dol_now()));
$after = (int) $db->fetch_object($db->query('SELECT COUNT(*) as nb FROM '.$prefix."actioncomm WHERE elementtype = 'opportunity@crm' AND fk_element = ".$dealId." AND code = 'AC_EMAIL'"))->nb;
crmCliCheck('the copy of a message sent from a mail client, with the marker, replaces the draft that has none', $rp['status'] === 'recorded' && $after === $before);

$db->rollback();
crmCliFinish();
