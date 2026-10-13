<?php
/* Copyright (C) 2026 CRM contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

/**
 * \file    email_compose.php
 * \ingroup crm
 * \brief   Review the e-mail suggested by a sequence step, then send it, send it from a mail client, or drop it.
 */

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/crmemaildraft.class.php';
crmStartPage();

$action = GETPOST('action', 'aZ09');
$draft = new CrmEmailDraft($db);
if ($draft->fetch(GETPOSTINT('id')) <= 0 || !crmCanReadOwner($user, (int) $draft->deal->fk_user_resp)) {
	accessforbidden();
}
$dealId = (int) $draft->deal->rowid;
$dealUrl = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.$dealId;
$self = $_SERVER['PHP_SELF'].'?id='.((int) $draft->event->id);
$canAct = $user->hasRight('crm', 'activity');

$to = GETPOSTISSET('to') ? trim(GETPOST('to', 'nohtml')) : $draft->contactEmail();
$subject = GETPOSTISSET('subject') ? GETPOST('subject', 'nohtml') : $draft->subject();
$body = GETPOSTISSET('body') ? GETPOST('body', 'nohtml') : (string) $draft->event->note_private;

if ($action !== '' && $action !== 'view') {
	crmRequireRight('activity');
	$done = '';
	if ($action === 'save') {
		$result = $draft->save($user, $subject, $body);
		$done = 'RecordSaved';
	} elseif ($action === 'send') {
		$result = $draft->send($user, $to, $subject, $body);
		$done = 'CrmEmailSentOk';
	} elseif ($action === 'sentmanually') {
		$result = $draft->markSentManually($user, $to, $subject, $body);
		$done = 'CrmEmailSentManuallyOk';
	} elseif ($action === 'discard') {
		$result = $draft->discard($user);
		$done = 'CrmDraftDiscarded';
	} else {
		accessforbidden();
	}
	if ($result > 0) {
		setEventMessages($langs->trans($done), null);
		header('Location: '.($action === 'save' ? $self : $dealUrl));
		exit;
	}
	setEventMessages(crmErrorText($draft->error), null, 'errors');
}

$archive = trim(getDolGlobalString('CRM_ARCHIVE_MAILBOX'));
$archiveCopy = (getDolGlobalString('CRM_ARCHIVE_COPY') === 'cc') ? 'cc' : 'bcc';

llxHeader('', $langs->trans('CrmComposeTitle'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmComposeTitle').' — <a href="'.$dealUrl.'">'.dol_escape_htmltag((string) $draft->deal->ref).'</a>', '', 'fa-handshake');
print '<p class="opacitymedium">'.$langs->trans('CrmComposeIntro').'</p>';
if ($draft->contactEmail() === '') {
	print '<div class="warning">'.$langs->trans('CrmNoContactEmail').'</div>';
}

print '<form id="crm-compose" method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="id" value="'.((int) $draft->event->id).'">';
$readonly = $canAct ? '' : ' readonly';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('CrmRecipient').'</td><td><input class="minwidth300" id="crm-to" name="to" value="'.dol_escape_htmltag($to).'"'.$readonly.'></td></tr>';
print '<tr><td>'.$langs->trans('CrmSubject').'</td><td><input class="quatrevingtpercent" id="crm-subject" name="subject" value="'.dol_escape_htmltag($subject).'"'.$readonly.'></td></tr>';
print '<tr><td>'.$langs->trans('CrmBody').'</td><td><textarea class="quatrevingtpercent" id="crm-body" name="body" rows="16"'.$readonly.'>'.dol_escape_htmltag($body, 0, 1).'</textarea></td></tr>';
if ($archive !== '') {
	print '<tr><td>'.$langs->trans('CrmArchiveMailbox').'</td><td>'.dol_escape_htmltag($archive).' <span class="opacitymedium">('.$langs->trans($archiveCopy === 'cc' ? 'CrmArchiveCopyCc' : 'CrmArchiveCopyBcc').')</span></td></tr>';
}
print '</table>';

if ($canAct) {
	print '<div class="center marginbottomonly">';
	print '<button type="submit" class="button" name="action" value="save">'.$langs->trans('CrmSaveDraft').'</button> ';
	print '<button type="submit" class="button" name="action" value="send" data-confirm="'.dol_escape_htmltag($langs->trans('CrmConfirmSend')).'">'.$langs->trans('CrmSendFromHere').'</button> ';
	print '<button type="button" class="button" id="crm-mailto">'.$langs->trans('CrmOpenMailClient').'</button> ';
	print '<button type="button" class="button" id="crm-copy">'.$langs->trans('CrmCopyText').'</button> ';
	print '<button type="submit" class="button" name="action" value="sentmanually" data-confirm="'.dol_escape_htmltag($langs->trans('CrmConfirmSentManually')).'">'.$langs->trans('CrmSentManually').'</button> ';
	print '<button type="submit" class="button butActionDelete" name="action" value="discard" data-confirm="'.dol_escape_htmltag($langs->trans('CrmConfirmDiscard')).'">'.$langs->trans('CrmDiscardDraft').'</button>';
	print '</div>';
	print '<ul class="opacitymedium">';
	foreach (array('CrmHelpSave', 'CrmHelpSend', 'CrmHelpMailClient', 'CrmHelpSentManually', 'CrmHelpDiscard') as $key) {
		print '<li>'.$langs->trans($key).'</li>';
	}
	print '</ul>';
}
print '</form>';
print '<p><a href="'.$dealUrl.'">'.$langs->trans('CrmBackToDeal').'</a></p>';

$mailtoCc = ($archive !== '' && $archiveCopy === 'cc') ? $archive : '';
$mailtoBcc = ($archive !== '' && $archiveCopy === 'bcc') ? $archive : '';
print '<script nonce="'.dol_escape_htmltag(getNonce()).'">';
print '(function () {';
print 'var form = document.getElementById("crm-compose");';
print 'if (!form) { return; }';
print 'var field = function (id) { return document.getElementById(id); };';
print 'form.addEventListener("click", function (event) {';
print 'var button = event.target.closest("button[data-confirm]");';
print 'if (button && !window.confirm(button.getAttribute("data-confirm"))) { event.preventDefault(); }';
print '});';
print 'var mailto = field("crm-mailto");';
print 'if (mailto) { mailto.addEventListener("click", function () {';
print 'var query = [];';
print 'var cc = '.json_encode($mailtoCc).'; var bcc = '.json_encode($mailtoBcc).';';
print 'if (cc) { query.push("cc=" + encodeURIComponent(cc)); }';
print 'if (bcc) { query.push("bcc=" + encodeURIComponent(bcc)); }';
print 'query.push("subject=" + encodeURIComponent(field("crm-subject").value));';
print 'query.push("body=" + encodeURIComponent(field("crm-body").value.replace(/\r?\n/g, "\r\n")));';
print 'window.location.href = "mailto:" + encodeURIComponent(field("crm-to").value) + "?" + query.join("&");';
print '}); }';
print 'var copy = field("crm-copy");';
print 'if (copy) { copy.addEventListener("click", function () {';
print 'var text = field("crm-subject").value + "\n\n" + field("crm-body").value;';
print 'var done = function () { copy.textContent = '.json_encode($langs->transnoentities('CrmCopied')).'; };';
print 'if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); return; }';
print 'var area = document.createElement("textarea"); area.value = text; document.body.appendChild(area); area.select(); document.execCommand("copy"); document.body.removeChild(area); done();';
print '}); }';
print '}());';
print '</script>';

llxFooter();
$db->close();
