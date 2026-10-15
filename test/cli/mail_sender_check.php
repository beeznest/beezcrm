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

crmCliFinish();
