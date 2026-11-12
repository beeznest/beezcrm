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

// The text preview is an ajax call to this page: it must not rotate the CSRF token the open form still holds.
if (isset($_POST['action']) && $_POST['action'] === 'previewtext') {
	define('NOTOKENRENEWAL', '1');
}

require_once __DIR__.'/lib/crm_bootstrap.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

require_once __DIR__.'/class/crmemaildraft.class.php';
require_once __DIR__.'/class/crmaireply.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/doleditor.class.php';
crmStartPage();

$action = GETPOST('action', 'aZ09');
// The buttons are input elements, like Dolibarr's own (the theme styles input.button and not <button>): their value is their
// label, so each one has its own name, act_<action>, and only the one that was clicked is posted.
foreach (array('aisuggest', 'save', 'send', 'sentmanually', 'discard') as $buttonAction) {
	if (GETPOSTISSET('act_'.$buttonAction) && $_SERVER['REQUEST_METHOD'] === 'POST') {
		$action = $buttonAction;
	}
}
// Every action but showing the form needs the token of the session (a link with act_send=1 must not send anything).
crmRequireToken($action);
$draft = new CrmEmailDraft($db);
if ($draft->fetch(GETPOSTINT('id')) <= 0 || !crmCanReadOwner($user, (int) $draft->deal->fk_user_resp)) {
	accessforbidden();
}
$dealId = (int) $draft->deal->rowid;
$dealUrl = dol_buildpath('/crm/opportunity_card.php', 1).'?id='.$dealId;
$self = $_SERVER['PHP_SELF'].'?id='.((int) $draft->event->id);
$canAct = $user->hasRight('crm', 'activity');

// The sender is one of the addresses crmSenderChoices() offers: the code posted by the form is looked up there, never used
// as an address. Without a choice posted, the one this user took last, else the default of the module.
$senderChoices = crmSenderChoices($db, $user);
$senderCode = crmSenderChoiceFor($senderChoices, GETPOST('sender', 'aZ09'), (string) ($user->conf->CRM_MAIL_FROM_CHOICE ?? ''));
$senderAddress = isset($senderChoices[$senderCode]) ? $senderChoices[$senderCode]['address'] : '';

// A hidden copy to the address of the user who sends, ticked by default. A posted form carries the box only when it is
// ticked, so "posted" is told by another field of the form.
$myAddress = trim((string) $user->email);
$canCopyMe = ($myAddress !== '' && isValidEmail($myAddress));
$copyMe = $canCopyMe && (GETPOSTISSET('format') ? GETPOSTINT('bccme') === 1 : true);

$to = GETPOSTISSET('to') ? trim(GETPOST('to', 'nohtml')) : $draft->contactEmail();
$subject = crmSingleLine(GETPOSTISSET('subject') ? strip_tags(GETPOST('subject', 'nohtml')) : $draft->subject(), 255);

// The message is plain text or HTML, never both at once: the text version of an HTML message is made from it when it
// is sent, as Dolibarr's mail library does, so the two can never disagree. A saved draft keeps the format it was in.
$stored = (string) $draft->event->note_private;
if (GETPOSTISSET('format')) {
	$format = (GETPOST('format', 'aZ09') === 'html') ? 'html' : 'text';
	$content = ($format === 'html') ? GETPOST('bodyhtml', 'restricthtml') : GETPOST('body', 'nohtml');
} else {
	$format = crmLooksLikeHtml($stored) ? 'html' : 'text';
	$content = $stored;
}

if ($action === 'previewtext') {
	// Plain-text version of the HTML in the editor, computed by the same function that prepares what is sent.
	top_httphead('application/json');
	print json_encode(array('text' => crmHtmlToText(GETPOST('html', 'restricthtml'))));
	exit;
}
$aiAvailable = CrmAiReply::isAvailable() && $canAct;
$aiPrompt = GETPOSTISSET('aiprompt') ? dol_substr(GETPOST('aiprompt', 'nohtml'), 0, 4000) : CrmAiReply::defaultPrompt();

// From v1.2.0: files attached to the message. They are kept on the draft, sent with the e-mail by Dolibarr, and stay on disk for
// the message history. A file that cannot be kept stops a send: a message must not leave without what the salesperson attached.
require_once __DIR__.'/class/crmmailfile.class.php';
$mailFiles = new CrmMailFile($db);
$mailFilesOn = crmMailFileSupported($db);
$attachError = '';
if ($mailFilesOn && $canAct && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, array('save', 'send', 'sentmanually', 'aisuggest'), true)) {
	foreach ((array) GETPOST('delmailfile', 'array:int') as $removeId) {
		$mailFiles->remove($user, (int) $removeId, (int) $draft->event->id);
	}
	if (!empty($_FILES['mailfiles']['name']) && is_array($_FILES['mailfiles']['name'])) {
		foreach ($_FILES['mailfiles']['name'] as $i => $uploadName) {
			$code = (int) ($_FILES['mailfiles']['error'][$i] ?? 0);
			if ((string) $uploadName === '' && $code === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			if ($mailFiles->add($user, $dealId, (int) $draft->event->id, (string) $uploadName, (string) ($_FILES['mailfiles']['tmp_name'][$i] ?? ''), $code) <= 0 && $attachError === '') {
				$attachError = $mailFiles->error;
			}
		}
	}
	if ($attachError !== '') {
		setEventMessages(crmErrorText($attachError), null, 'errors');
		if ($action === 'send' || $action === 'sentmanually') {
			$action = 'view';
		}
	}
}

if ($action === 'aisuggest') {
	// Fills the text box with a proposal. Nothing is saved or sent: the salesperson reads, edits and chooses.
	crmRequireRight('activity');
	if (!$aiAvailable) {
		accessforbidden();
	}
	$reply = new CrmAiReply($db);
	$suggestion = $reply->allow($user) ? $reply->suggest($dealId, $aiPrompt, $subject, $content) : null;
	if ($suggestion === null) {
		$aiError = (strpos($reply->error, 'CrmError') === 0) ? crmErrorText($reply->error) : $langs->trans('CrmAiError', dol_escape_htmltag($reply->error));
		if ($user->admin) {
			$aiError .= ' <a href="'.DOL_URL_ROOT.'/ai/admin/setup.php">'.$langs->trans('CrmAiGoToSetup').'</a>';
		}
		setEventMessages($aiError, null, 'errors');
	} else {
		$content = ($format === 'html') ? crmTextToHtml($suggestion) : $suggestion;
		setEventMessages($langs->trans('CrmAiInserted'), null, 'mesgs');
	}
	$action = 'view';
}

if ($action !== '' && $action !== 'view') {
	crmRequireRight('activity');
	$done = '';
	if ($action === 'save') {
		$result = $draft->save($user, $subject, $content);
		$done = 'RecordSaved';
	} elseif ($action === 'send') {
		$result = $draft->send($user, $to, $subject, $content, $format === 'html', $senderAddress, $copyMe ? $myAddress : '');
		$done = 'CrmEmailSentOk';
	} elseif ($action === 'sentmanually') {
		$result = $draft->markSentManually($user, $to, $subject, $content, $senderAddress);
		$done = 'CrmEmailSentManuallyOk';
	} elseif ($action === 'discard') {
		$result = $draft->discard($user);
		$done = 'CrmDraftDiscarded';
	} else {
		accessforbidden();
	}
	if ($result > 0) {
		// The sender used is remembered for this user: the next e-mail starts from it.
		if (($action === 'send' || $action === 'sentmanually') && $senderCode !== '' && ($user->conf->CRM_MAIL_FROM_CHOICE ?? '') !== $senderCode) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php'; // dol_set_user_param(), not loaded on every request
			dol_set_user_param($db, $conf, $user, array('CRM_MAIL_FROM_CHOICE' => $senderCode));
		}
		setEventMessages($langs->trans($done), null);
		header('Location: '.($action === 'save' ? $self : $dealUrl));
		exit;
	}
	setEventMessages(crmErrorText($draft->error), null, 'errors');
}

$bodyHtml = ($format === 'html') ? $content : crmTextToHtml($content);
$bodyText = ($format === 'html') ? crmHtmlToText($content) : $content;
$archive = trim(getDolGlobalString('CRM_ARCHIVE_MAILBOX'));
$archiveCopy = (getDolGlobalString('CRM_ARCHIVE_COPY') === 'cc') ? 'cc' : 'bcc';

llxHeader('', $langs->trans('CrmComposeTitle'), '', '', 0, 0, '', array('/crm/css/crm.css'));
print load_fiche_titre($langs->trans('CrmComposeTitle').' — <a href="'.$dealUrl.'">'.dol_escape_htmltag((string) $draft->deal->ref).'</a>', '', 'fa-handshake');
print crmHelp($langs->trans('CrmComposeIntro'));
if ($draft->contactEmail() === '') {
	print '<div class="warning">'.$langs->trans('CrmNoContactEmail').'</div>';
}

print '<form id="crm-compose" method="POST" enctype="multipart/form-data" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="id" value="'.((int) $draft->event->id).'">';
$readonly = $canAct ? '' : ' readonly';
print '<table class="border centpercent">';
// The address "Send from here" sends from: the default of the module, the user's own address or the mailbox of a lead
// collector, to choose among. With a single address it is shown as text.
print '<tr><td class="titlefield">'.$langs->trans('CrmSender').'</td><td>';
if (count($senderChoices) === 0) {
	print '<div class="warning">'.$langs->trans('CrmNoSender').'</div>';
} else {
	$senderLabel = function (string $code) use ($senderChoices, $langs): string {
		$choice = $senderChoices[$code];
		if ($choice['kind'] === 'user') {
			return $langs->transnoentities('CrmSenderOptionUser', $choice['address']);
		}
		if ($choice['kind'] === 'lead') {
			return $langs->transnoentities('CrmSenderOptionLead', $choice['address'], $choice['ref']);
		}
		$source = crmSenderSource();
		return $langs->transnoentities('CrmSenderOptionDefault', $choice['address'], $source !== '' ? $langs->transnoentities('CrmSenderSource'.ucfirst($source)) : '');
	};
	if (count($senderChoices) === 1) {
		print '<strong>'.dol_escape_htmltag($senderLabel($senderCode)).'</strong>';
		print '<input type="hidden" name="sender" value="'.dol_escape_htmltag($senderCode).'">';
	} else {
		print '<select name="sender" id="crm-sender"'.($canAct ? '' : ' disabled').'>';
		foreach ($senderChoices as $code => $choice) {
			print '<option value="'.dol_escape_htmltag((string) $code).'"'.($code === $senderCode ? ' selected' : '').'>'.dol_escape_htmltag($senderLabel((string) $code)).'</option>';
		}
		print '</select>';
	}
}
if (!isset($senderChoices['user']) && !isValidEmail(trim((string) $user->email)) && count($senderChoices) > 0) {
	print ' <span class="opacitymedium">'.$langs->trans('CrmSenderNoUserEmail').'</span>';
}
print crmHelp($langs->trans('CrmSenderHelp'), 'span', true).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmRecipient').'</td><td><input class="minwidth300" id="crm-to" name="to" value="'.dol_escape_htmltag($to).'"'.$readonly.'></td></tr>';
print '<tr><td>'.$langs->trans('CrmCopyToMe').'</td><td>';
if ($canCopyMe) {
	print '<label><input type="checkbox" id="crm-bccme" name="bccme" value="1"'.($copyMe ? ' checked' : '').($canAct ? '' : ' disabled').' data-label="'.dol_escape_htmltag($langs->transnoentities('CrmCopyToMeSend', $myAddress)).'"> '.$langs->trans('CrmCopyToMeLabel', dol_escape_htmltag($myAddress)).'</label>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('CrmCopyToMeNoEmail').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('CrmSubject').'</td><td><input class="quatrevingtpercent" id="crm-subject" name="subject" value="'.dol_escape_htmltag($subject).'"'.$readonly.'></td></tr>';
print '<tr><td>'.$langs->trans('CrmFormat').'</td><td>';
print '<label><input type="radio" name="format" value="html"'.($format === 'html' ? ' checked' : '').($canAct ? '' : ' disabled').'> '.$langs->trans('CrmFormatHtml').'</label> &nbsp; ';
print '<label><input type="radio" name="format" value="text"'.($format === 'text' ? ' checked' : '').($canAct ? '' : ' disabled').'> '.$langs->trans('CrmFormatText').'</label>';
print crmHelp($langs->trans('CrmFormatHelp'), 'span', true).'</td></tr>';
// The link every e-mail of the deal ends with: Send adds it itself, a message written in a mail client gets it from here.
require_once __DIR__.'/class/crmoptout.class.php';
$optOutUrl = (new CrmOptOut($db))->urlForDeal($dealId);
$optOutText = $optOutUrl !== '' ? crmOptOutLinkText($dealId) : '';
print '<tr><td>'.$langs->trans('CrmBody').'</td><td>';
print '<div class="crm-tabs"><button type="button" id="crm-tab-html" class="crm-tab">'.$langs->trans('CrmTabHtml').'</button><button type="button" id="crm-tab-plain" class="crm-tab">'.$langs->trans('CrmTabPlain').'</button></div>';
print '<div id="crm-pane-html" class="crm-pane">';
$editor = new DolEditor('bodyhtml', $bodyHtml, '', 320, 'dolibarr_mailings', 'In', true, false, $canAct ? true : false, 12, '95%', $canAct ? 0 : 1);
$editor->Create(0, '', true);
print '</div>';
print '<div id="crm-pane-plain" class="crm-pane"><textarea class="quatrevingtpercent" id="crm-body" name="body" rows="16"'.$readonly.'>'.dol_escape_htmltag($bodyText, 0, 1).'</textarea>';
print '<div id="crm-plain-note">'.crmHelp($langs->trans('CrmPlainAlternativeNote'), 'div').'</div></div>';
if ($optOutUrl !== '') {
	// Shown as text, not as a link: the address is the one the prospect uses, and opening it from here would not be a test.
	print '<div class="crm-optout-preview opacitymedium"><span class="fa fa-link paddingright"></span>'.dol_escape_htmltag($langs->trans('CrmOptOutAppended')).' <em>'.dol_escape_htmltag($optOutText).'</em> <code>'.dol_escape_htmltag($optOutUrl).'</code></div>';
}
print '</td></tr>';
if ($mailFilesOn) {
	// Files sent with the message, kept on disk for the message history. Shown whether or not the draft has any yet.
	print '<tr><td>'.$langs->trans('CrmMailAttachments').'</td><td>';
	$fileUrl = dol_buildpath('/crm/mail_file.php', 1);
	$current = $mailFiles->fetchForAction((int) $draft->event->id);
	if (count($current) > 0) {
		print '<ul class="crm-note-files">';
		foreach ($current as $file) {
			print '<li>'.img_picto('', 'fa-paperclip', 'class="pictofixedwidth"').'<a href="'.dol_escape_htmltag($fileUrl.'?id='.((int) $file->rowid)).'">'.dol_escape_htmltag((string) $file->filename).'</a> <span class="opacitymedium">('.dol_print_size((int) $file->filesize, 1, 1).')</span>';
			if ($canAct) {
				print ' <label><input type="checkbox" name="delmailfile[]" value="'.((int) $file->rowid).'"> '.dol_escape_htmltag($langs->trans('CrmMailAttachmentRemove')).'</label>';
			}
			print '</li>';
		}
		print '</ul>';
	}
	if ($canAct) {
		print '<input type="file" name="mailfiles[]" multiple> <span class="opacitymedium">'.dol_escape_htmltag($langs->trans('CrmMailAttachmentsMax', dol_print_size(CrmNote::maxFileBytes(), 1, 1), CrmMailFile::MAX_FILES, dol_print_size(CrmMailFile::MAX_TOTAL, 1, 1))).'</span>';
	}
	print crmHelp($langs->trans('CrmMailAttachmentsHelp'), 'div').'</td></tr>';
}
if ($aiAvailable) {
	print '<tr><td>'.$langs->trans('CrmAiAssistant').'</td><td>';
	// Where the text goes and whether it is masked stay visible: that is a notice about data leaving, not an explanation.
	print '<p class="opacitymedium">'.$langs->trans('CrmAiService', dol_escape_htmltag(CrmAiReply::serviceLabel())).' ';
	print $langs->trans(getDolGlobalInt('AI_PRIVACY_REDACTION', 0) ? 'CrmAiMasked' : 'CrmAiNotMasked').'</p>';
	print crmHelp($langs->trans('CrmAiIntro', dol_escape_htmltag(CrmAiReply::serviceLabel())));
	print '<textarea class="quatrevingtpercent" id="crm-ai-prompt" name="aiprompt" rows="6">'.dol_escape_htmltag($aiPrompt, 0, 1).'</textarea><br>';
	print '<input type="submit" class="button" name="act_aisuggest" value="'.dol_escape_htmltag($langs->trans('CrmAiSuggest')).'"> ';
	print '<input type="button" class="button" id="crm-ai-restore" value="'.dol_escape_htmltag($langs->trans('CrmAiRestorePrompt')).'">';
	print crmHelp($langs->trans('CrmAiHelp'), 'span', true);
	print '</td></tr>';
}
if ($archive !== '') {
	print '<tr><td>'.$langs->trans('CrmArchiveMailbox').'</td><td>'.dol_escape_htmltag($archive).' <span class="opacitymedium">('.$langs->trans($archiveCopy === 'cc' ? 'CrmArchiveCopyCc' : 'CrmArchiveCopyBcc').')</span></td></tr>';
}
print '</table>';

if ($canAct) {
	print '<div class="center marginbottomonly">';
	print '<input type="submit" class="button" name="act_save" value="'.dol_escape_htmltag($langs->trans('CrmSaveDraft')).'"> ';
	print '<input type="submit" class="button" name="act_send" value="'.dol_escape_htmltag($langs->trans('CrmSendFromHere')).'" data-with-sender="1" data-confirm="'.dol_escape_htmltag($langs->trans('CrmConfirmSend')).'"> ';
	print '<input type="button" class="button" id="crm-mailto" value="'.dol_escape_htmltag($langs->trans('CrmOpenMailClient')).'"> ';
	print '<input type="button" class="button" id="crm-copy" value="'.dol_escape_htmltag($langs->trans('CrmCopyText')).'"> ';
	print '<input type="submit" class="button" name="act_sentmanually" value="'.dol_escape_htmltag($langs->trans('CrmSentManually')).'" data-confirm="'.dol_escape_htmltag($langs->trans('CrmConfirmSentManually')).'"> ';
	print '<input type="submit" class="button butActionDelete" name="act_discard" value="'.dol_escape_htmltag($langs->trans('CrmDiscardDraft')).'" data-confirm="'.dol_escape_htmltag($langs->trans('CrmConfirmDiscard')).'">';
	print '</div>';
	$helpItems = '';
	foreach (array('CrmHelpSave', 'CrmHelpSend', 'CrmHelpMailClient', 'CrmHelpSentManually', 'CrmHelpDiscard') as $key) {
		$helpItems .= '<li>'.$langs->trans($key).'</li>';
	}
	print crmHelp($helpItems, 'ul');
	print crmHelp($langs->trans('CrmOptOutComposeNote'));
}
print '</form>';
print '<p><a href="'.$dealUrl.'">'.$langs->trans('CrmBackToDeal').'</a></p>';

$mailtoCc = ($archive !== '' && $archiveCopy === 'cc') ? $archive : '';
$mailtoBcc = ($archive !== '' && $archiveCopy === 'bcc') ? $archive : '';
$js = <<<'JS'
(function () {
	var form = document.getElementById("crm-compose");
	if (!form) { return; }
	var cfg = __CONFIG__;
	var field = function (id) { return document.getElementById(id); };
	var plain = field("crm-body");
	var paneHtml = field("crm-pane-html"), panePlain = field("crm-pane-plain");
	var tabHtml = field("crm-tab-html"), tabPlain = field("crm-tab-plain");
	var note = field("crm-plain-note");
	var radios = form.querySelectorAll("input[name=format]");
	var csrf = form.querySelector("input[name=token]").value;
	var format = cfg.format;

	var editor = function () { return window.CKEDITOR && window.CKEDITOR.instances ? window.CKEDITOR.instances.bodyhtml : null; };
	var getHtml = function () { var e = editor(); return e ? e.getData() : field("bodyhtml").value; };
	var setHtml = function (v) { var e = editor(); if (e) { e.setData(v); } else { field("bodyhtml").value = v; } };
	var textToHtml = function (t) {
		t = t.replace(/\r\n?/g, "\n").trim();
		if (t === "") { return ""; }
		var esc = function (x) { return x.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); };
		return t.split(/\n{2,}/).map(function (b) { return "<p>" + esc(b).replace(/\n/g, "<br>\n") + "</p>"; }).join("\n");
	};
	var htmlToText = function (html, done) {
		var body = new URLSearchParams({ token: csrf, action: "previewtext", id: cfg.id, html: html });
		fetch(cfg.url, { method: "POST", body: body, credentials: "same-origin" })
			.then(function (r) { return r.json(); })
			.then(function (d) { done(d.text || ""); })
			.catch(function () { done(plain.value); });
	};
	var show = function (which) {
		var isHtml = which === "html";
		paneHtml.className = "crm-pane" + (isHtml ? "" : " crm-pane-off");
		panePlain.className = "crm-pane" + (isHtml ? " crm-pane-off" : "");
		tabHtml.className = "crm-tab" + (isHtml ? " crm-tab-on" : "");
		tabPlain.className = "crm-tab" + (isHtml ? "" : " crm-tab-on");
	};
	var refreshPlain = function (done) {
		if (format !== "html") { if (done) { done(); } return; }
		htmlToText(getHtml(), function (t) { plain.value = t; if (done) { done(); } });
	};
	var apply = function () {
		var isHtml = format === "html";
		plain.readOnly = isHtml || cfg.readonly;
		if (note) { note.style.display = isHtml ? "" : "none"; }
		tabHtml.disabled = !isHtml;
		tabPlain.textContent = isHtml ? cfg.tabPlainAlt : cfg.tabPlain;
		show(isHtml ? "html" : "plain");
	};

	tabHtml.addEventListener("click", function () { if (format === "html") { show("html"); } });
	tabPlain.addEventListener("click", function () { show("plain"); if (format === "html") { refreshPlain(); } });
	Array.prototype.forEach.call(radios, function (radio) {
		radio.addEventListener("change", function () {
			var target = radio.value;
			if (!radio.checked || target === format) { return; }
			if (target === "html") {
				setHtml(textToHtml(plain.value));
				format = "html";
				apply();
			} else if (window.confirm(cfg.confirmToText)) {
				htmlToText(getHtml(), function (t) { plain.value = t; format = "text"; apply(); });
			} else {
				form.querySelector("input[name=format][value=html]").checked = true;
			}
		});
	});

	form.addEventListener("click", function (event) {
		var button = event.target.closest("input[data-confirm]");
		if (!button) { return; }
		var message = button.getAttribute("data-confirm");
		var senderSelect = document.getElementById("crm-sender");
		// A send names the address it is sent from, as chosen in the list.
		if (button.hasAttribute("data-with-sender") && senderSelect && senderSelect.selectedIndex >= 0) {
			message += "\n\n" + senderSelect.options[senderSelect.selectedIndex].text;
		}
		var copyBox = document.getElementById("crm-bccme");
		if (button.hasAttribute("data-with-sender") && copyBox && copyBox.checked) {
			message += "\n" + copyBox.getAttribute("data-label");
		}
		if (!window.confirm(message)) { event.preventDefault(); }
	});
	form.addEventListener("submit", function (event) {
		var e = editor();
		if (e) { e.updateElement(); }
		var button = event.submitter;
		if (button && button.name === "act_aisuggest") {
			window.setTimeout(function () { button.value = cfg.aiWaiting; }, 0);
		}
	});

	var mailto = field("crm-mailto");
	if (mailto) {
		mailto.addEventListener("click", function () {
			refreshPlain(function () {
				var query = [];
				if (cfg.cc) { query.push("cc=" + encodeURIComponent(cfg.cc)); }
				if (cfg.bcc) { query.push("bcc=" + encodeURIComponent(cfg.bcc)); }
				query.push("subject=" + encodeURIComponent(field("crm-subject").value));
				var tail = cfg.optOutUrl ? "\n\n--\n" + cfg.optOutText + "\n" + cfg.optOutUrl + "\n" : "";
				query.push("body=" + encodeURIComponent((plain.value + tail).replace(/\r?\n/g, "\r\n")));
				window.location.href = "mailto:" + encodeURIComponent(field("crm-to").value) + "?" + query.join("&");
			});
		});
	}
	var restore = field("crm-ai-restore");
	if (restore) {
		restore.addEventListener("click", function () { field("crm-ai-prompt").value = cfg.aiPrompt; });
	}
	var copy = field("crm-copy");
	if (copy) {
		copy.addEventListener("click", function () {
			var done = function () { copy.textContent = cfg.copied; };
			refreshPlain(function () {
				var tail = cfg.optOutUrl ? "\n\n--\n" + cfg.optOutText + "\n" + cfg.optOutUrl + "\n" : "";
				var text = field("crm-subject").value + "\n\n" + plain.value + tail;
				if (format === "html" && window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
					var html = "<p><strong>" + field("crm-subject").value.replace(/&/g, "&amp;").replace(/</g, "&lt;") + "</strong></p>" + getHtml() + (cfg.optOutUrl ? "<p>-- <br><a href=\"" + cfg.optOutUrl + "\">" + cfg.optOutText.replace(/&/g, "&amp;").replace(/</g, "&lt;") + "</a></p>" : "");
					navigator.clipboard.write([new ClipboardItem({
						"text/html": new Blob([html], { type: "text/html" }),
						"text/plain": new Blob([text], { type: "text/plain" })
					})]).then(done);
					return;
				}
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); return; }
				var area = document.createElement("textarea"); area.value = text; document.body.appendChild(area); area.select(); document.execCommand("copy"); document.body.removeChild(area); done();
			});
		});
	}
	apply();
}());
JS;
$config = array(
	'format' => $format,
	'id' => (int) $draft->event->id,
	'url' => $_SERVER['PHP_SELF'],
	'readonly' => !$canAct,
	'optOutUrl' => $optOutUrl,
	'optOutText' => $optOutText,
	'cc' => $mailtoCc,
	'bcc' => $mailtoBcc,
	'aiPrompt' => CrmAiReply::defaultPrompt(),
	'aiWaiting' => $langs->transnoentities('CrmAiWaiting'),
	'copied' => $langs->transnoentities('CrmCopied'),
	'tabPlain' => $langs->transnoentities('CrmTabPlain'),
	'tabPlainAlt' => $langs->transnoentities('CrmTabPlainAlt'),
	'confirmToText' => $langs->transnoentities('CrmConfirmToText'),
);
print '<script nonce="'.dol_escape_htmltag(getNonce()).'">'.str_replace('__CONFIG__', json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP), $js).'</script>';

crmHelpToggle();
llxFooter();
$db->close();
