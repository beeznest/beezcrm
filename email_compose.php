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
$aiPrompt = GETPOSTISSET('aiprompt') ? GETPOST('aiprompt', 'nohtml') : CrmAiReply::defaultPrompt();

if ($action === 'aisuggest') {
	// Fills the text box with a proposal. Nothing is saved or sent: the salesperson reads, edits and chooses.
	crmRequireRight('activity');
	if (!$aiAvailable) {
		accessforbidden();
	}
	$reply = new CrmAiReply($db);
	$suggestion = $reply->suggest($dealId, $aiPrompt, $subject, $content);
	if ($suggestion === null) {
		$aiError = (strpos($reply->error, 'CrmError') === 0) ? crmErrorText($reply->error) : $langs->trans('CrmAiError', $reply->error);
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
		$result = $draft->send($user, $to, $subject, $content, $format === 'html');
		$done = 'CrmEmailSentOk';
	} elseif ($action === 'sentmanually') {
		$result = $draft->markSentManually($user, $to, $subject, $content);
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

print '<form id="crm-compose" method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="id" value="'.((int) $draft->event->id).'">';
$readonly = $canAct ? '' : ' readonly';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('CrmRecipient').'</td><td><input class="minwidth300" id="crm-to" name="to" value="'.dol_escape_htmltag($to).'"'.$readonly.'></td></tr>';
print '<tr><td>'.$langs->trans('CrmSubject').'</td><td><input class="quatrevingtpercent" id="crm-subject" name="subject" value="'.dol_escape_htmltag($subject).'"'.$readonly.'></td></tr>';
print '<tr><td>'.$langs->trans('CrmFormat').'</td><td>';
print '<label><input type="radio" name="format" value="html"'.($format === 'html' ? ' checked' : '').($canAct ? '' : ' disabled').'> '.$langs->trans('CrmFormatHtml').'</label> &nbsp; ';
print '<label><input type="radio" name="format" value="text"'.($format === 'text' ? ' checked' : '').($canAct ? '' : ' disabled').'> '.$langs->trans('CrmFormatText').'</label>';
print crmHelp($langs->trans('CrmFormatHelp'), 'span', true).'</td></tr>';
print '<tr><td>'.$langs->trans('CrmBody').'</td><td>';
print '<div class="crm-tabs"><button type="button" id="crm-tab-html" class="crm-tab">'.$langs->trans('CrmTabHtml').'</button><button type="button" id="crm-tab-plain" class="crm-tab">'.$langs->trans('CrmTabPlain').'</button></div>';
print '<div id="crm-pane-html" class="crm-pane">';
$editor = new DolEditor('bodyhtml', $bodyHtml, '', 320, 'dolibarr_mailings', 'In', true, false, $canAct ? true : false, 12, '95%', $canAct ? 0 : 1);
$editor->Create(0, '', true);
print '</div>';
print '<div id="crm-pane-plain" class="crm-pane"><textarea class="quatrevingtpercent" id="crm-body" name="body" rows="16"'.$readonly.'>'.dol_escape_htmltag($bodyText, 0, 1).'</textarea>';
print crmHelp($langs->trans('CrmPlainAlternativeNote'), 'div').'</div>';
print '</td></tr>';
if ($aiAvailable) {
	print '<tr><td>'.$langs->trans('CrmAiAssistant').'</td><td>';
	// Where the text goes and whether it is masked stay visible: that is a notice about data leaving, not an explanation.
	print '<p class="opacitymedium">'.$langs->trans('CrmAiService', dol_escape_htmltag(CrmAiReply::serviceLabel())).' ';
	print $langs->trans(getDolGlobalInt('AI_PRIVACY_REDACTION', 0) ? 'CrmAiMasked' : 'CrmAiNotMasked').'</p>';
	print crmHelp($langs->trans('CrmAiIntro', dol_escape_htmltag(CrmAiReply::serviceLabel())));
	print '<textarea class="quatrevingtpercent" id="crm-ai-prompt" name="aiprompt" rows="6">'.dol_escape_htmltag($aiPrompt, 0, 1).'</textarea><br>';
	print '<button type="submit" class="button" name="action" value="aisuggest">'.$langs->trans('CrmAiSuggest').'</button> ';
	print '<button type="button" class="button" id="crm-ai-restore">'.$langs->trans('CrmAiRestorePrompt').'</button>';
	print crmHelp($langs->trans('CrmAiHelp'), 'span', true);
	print '</td></tr>';
}
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
	$helpItems = '';
	foreach (array('CrmHelpSave', 'CrmHelpSend', 'CrmHelpMailClient', 'CrmHelpSentManually', 'CrmHelpDiscard') as $key) {
		$helpItems .= '<li>'.$langs->trans($key).'</li>';
	}
	print crmHelp($helpItems, 'ul');
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
		note.style.display = isHtml ? "" : "none";
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
		var button = event.target.closest("button[data-confirm]");
		if (button && !window.confirm(button.getAttribute("data-confirm"))) { event.preventDefault(); }
	});
	form.addEventListener("submit", function (event) {
		var e = editor();
		if (e) { e.updateElement(); }
		var button = event.submitter;
		if (button && button.value === "aisuggest") {
			window.setTimeout(function () { button.textContent = cfg.aiWaiting; }, 0);
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
				query.push("body=" + encodeURIComponent(plain.value.replace(/\r?\n/g, "\r\n")));
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
				var text = field("crm-subject").value + "\n\n" + plain.value;
				if (format === "html" && window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
					var html = "<p><strong>" + field("crm-subject").value.replace(/&/g, "&amp;").replace(/</g, "&lt;") + "</strong></p>" + getHtml();
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
