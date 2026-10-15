<?php
declare(strict_types=1);

/**
 * \file    test/cli/mail_sender_check.php
 * \ingroup crm
 * \brief   The sender of the e-mails the module sends, and where it comes from. The settings are changed in memory only.
 *          See crm_cli_bootstrap.inc.php.
 */

require_once __DIR__.'/crm_cli_bootstrap.inc.php';

/** @var Conf $conf */
/** @var Societe $mysoc */
$conf->global->CRM_MAIL_FROM = 'crm@example.com';
$conf->global->MAIN_MAIL_EMAIL_FROM = 'main@example.com';
$mysoc->email = 'company@example.com';
crmCliCheck('the CRM setting comes first', crmSender() === 'crm@example.com' && crmSenderSource() === 'crm');

$conf->global->CRM_MAIL_FROM = '';
crmCliCheck('then the sender of the Dolibarr e-mail setup', crmSender() === 'main@example.com' && crmSenderSource() === 'main');

$conf->global->MAIN_MAIL_EMAIL_FROM = '';
crmCliCheck('then the e-mail of the company', crmSender() === 'company@example.com' && crmSenderSource() === 'company');

$mysoc->email = '';
crmCliCheck('with nothing set there is no sender', crmSender() === '' && crmSenderSource() === '');

// The addresses a salesperson may choose to send from.
$db->begin();
$prefix = $db->prefix();
$insertCollector = function (string $ref, string $login, int $status, string $actionType) use ($db, $prefix, $conf): int {
	$db->query('INSERT INTO '.$prefix."emailcollector_emailcollector (entity, ref, label, login, status, source_directory, date_creation, fk_user_creat) VALUES (".((int) $conf->entity).", '".$db->escape($ref)."', 'Z', '".$db->escape($login)."', ".$status.", 'INBOX', '".$db->idate(dol_now())."', 1)");
	$id = (int) $db->last_insert_id($prefix.'emailcollector_emailcollector');
	if ($actionType !== '') {
		$db->query('INSERT INTO '.$prefix."emailcollector_emailcollectoraction (fk_emailcollector, type, status, date_creation, fk_user_creat) VALUES (".$id.", '".$db->escape($actionType)."', 1, '".$db->idate(dol_now())."', 1)");
	}
	return $id;
};
// The collectors of this instance are turned off for the length of the check (rolled back), so that only the ones made here count.
$db->query('UPDATE '.$prefix.'emailcollector_emailcollector SET status = 0 WHERE entity = '.((int) $conf->entity));
$leadId = $insertCollector('ZLead', 'leads@example.com', 1, 'project');
$insertCollector('ZOff', 'off@example.com', 0, 'project');
$insertCollector('ZTicket', 'ticket@example.com', 1, 'ticket');
$insertCollector('ZLogin', 'not-an-address', 1, 'project');

$conf->global->CRM_MAIL_FROM = 'crm@example.com';
$user->email = 'me@example.com';
$choices = crmSenderChoices($db, $user);
crmCliCheck('the default, the user and the lead mailbox are offered, in that order', array_keys($choices) === array('default', 'user', 'lead'.$leadId));
crmCliCheck('only an active lead collector whose login is an address counts', count(crmLeadCollectorMailboxes($db)) === 1 && crmLeadCollectorMailboxes($db)[0]['address'] === 'leads@example.com');
crmCliCheck('each choice carries its address', $choices['default']['address'] === 'crm@example.com' && $choices['user']['address'] === 'me@example.com' && $choices['lead'.$leadId]['ref'] === 'ZLead');

$user->email = 'CRM@example.com';
crmCliCheck('an address already offered is not offered twice', !isset(crmSenderChoices($db, $user)['user']));
$user->email = 'not-an-address';
crmCliCheck('an invalid address of the user is left out', !isset(crmSenderChoices($db, $user)['user']));
$user->email = 'me@example.com';

crmCliCheck('a posted code that is not offered is ignored', crmSenderChoiceFor($choices, 'evil@example.com', '') === 'default' && crmSenderChoiceFor($choices, 'lead999', '') === 'default');
crmCliCheck('the choice posted comes first, then the remembered one, then the default', crmSenderChoiceFor($choices, 'user', 'lead'.$leadId) === 'user' && crmSenderChoiceFor($choices, '', 'lead'.$leadId) === 'lead'.$leadId && crmSenderChoiceFor($choices, '', 'gone') === 'default');
crmCliCheck('with no default sender the first address available is chosen', crmSenderChoiceFor(array('user' => $choices['user']), '', '') === 'user' && crmSenderChoiceFor(array(), '', '') === '');

$conf->global->CRM_MAIL_FROM = '';
$conf->global->MAIN_MAIL_EMAIL_FROM = '';
$mysoc->email = '';
$user->email = '';
$db->query('UPDATE '.$prefix."emailcollector_emailcollector SET status = 0 WHERE ref = 'ZLead'");
crmCliCheck('with no address at all nothing is offered', count(crmSenderChoices($db, $user)) === 0);

// crmSendMail uses the sender it is given, or the default of the module. Sending is disabled in memory: nothing leaves.
$conf->global->MAIN_DISABLE_ALL_MAILS = '1';
$conf->global->CRM_MAIL_FROM = 'crm@example.com';
crmCliCheck('a chosen sender is the one the message is sent from', crmSendMail('to@example.com', 'S', 'B', 0, false, 'chosen@example.com')['from'] === 'chosen@example.com');
crmCliCheck('without a sender the default of the module is used', crmSendMail('to@example.com', 'S', 'B')['from'] === 'crm@example.com');
crmCliCheck('a sender that is not an address is replaced by the default', crmSendMail('to@example.com', 'S', 'B', 0, false, 'x; Bcc: evil@example.com')['from'] === 'crm@example.com');

// The copies reach the mail library as hidden recipients.
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
$copies = crmCopyRecipients('crm@example.com', 'bcc', 'me@example.com', true);
$mail = new CMailFile('S', 'to@example.com', 'from@example.com', 'B', array(), array(), array(), $copies['cc'], $copies['bcc'], 0, 0);
crmCliCheck('the archive mailbox and my own address are hidden recipients of the message', strpos((string) $mail->addr_bcc, 'crm@example.com') !== false && strpos((string) $mail->addr_bcc, 'me@example.com') !== false && (string) $mail->addr_cc === '');

crmCliFinish();
