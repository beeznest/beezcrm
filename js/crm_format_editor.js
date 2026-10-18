/**
 * Plain text or formatted (HTML) choice for a text area that Dolibarr's WYSIWYG editor can replace.
 *
 * The page prints two panes inside a form: the editor (a textarea named cfg.htmlName, turned into CKEditor by DolEditor)
 * and a plain-text textarea. The message exists in one form only. In HTML format the plain pane shows the plain-text
 * version, made by the server from the HTML (action "previewtext" of the page) and read-only; in plain format it is the
 * editor. Switching to HTML turns the text into paragraphs; switching back asks first, because the formatting is lost.
 */
(function () {
	"use strict";

	function init(cfg) {
		var form = document.getElementById(cfg.formId);
		if (!form) { return; }
		var field = function (id) { return document.getElementById(id); };
		var plain = field(cfg.plainId);
		var paneHtml = field(cfg.paneHtmlId), panePlain = field(cfg.panePlainId);
		var tabHtml = field(cfg.tabHtmlId), tabPlain = field(cfg.tabPlainId);
		var note = field(cfg.noteId);
		var radios = form.querySelectorAll("input[name=" + cfg.radioName + "]");
		var format = cfg.format;

		var editor = function () { return window.CKEDITOR && window.CKEDITOR.instances ? window.CKEDITOR.instances[cfg.htmlName] : null; };
		var getHtml = function () { var e = editor(); return e ? e.getData() : form.querySelector("[name=" + cfg.htmlName + "]").value; };
		var setHtml = function (v) {
			var e = editor();
			if (e) { e.setData(v); } else { form.querySelector("[name=" + cfg.htmlName + "]").value = v; }
		};
		var textToHtml = function (t) {
			t = t.replace(/\r\n?/g, "\n").trim();
			if (t === "") { return ""; }
			var esc = function (x) { return x.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); };
			return t.split(/\n{2,}/).map(function (b) { return "<p>" + esc(b).replace(/\n/g, "<br>\n") + "</p>"; }).join("\n");
		};
		var htmlToText = function (html, done) {
			var body = new URLSearchParams({ token: cfg.token, action: "previewtext", id: cfg.id, html: html });
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
		var apply = function () {
			var isHtml = format === "html";
			plain.readOnly = isHtml;
			if (note) { note.style.display = isHtml ? "" : "none"; }
			tabHtml.disabled = !isHtml;
			tabPlain.textContent = isHtml ? cfg.tabPlainAlt : cfg.tabPlain;
			show(isHtml ? "html" : "plain");
		};

		tabHtml.addEventListener("click", function () { if (format === "html") { show("html"); } });
		tabPlain.addEventListener("click", function () {
			show("plain");
			if (format === "html") { htmlToText(getHtml(), function (t) { plain.value = t; }); }
		});
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
					form.querySelector("input[name=" + cfg.radioName + "][value=html]").checked = true;
				}
			});
		});
		form.addEventListener("submit", function () {
			var e = editor();
			if (e) { e.updateElement(); }
		});

		// The text only matters for an e-mail step: the other kinds have no message.
		var kind = cfg.kindId ? field(cfg.kindId) : null;
		if (kind) {
			var toggle = function () {
				var isEmail = kind.value === "email";
				(cfg.onlyEmailIds || []).forEach(function (id) {
					var el = field(id);
					if (el) { el.style.display = isEmail ? "" : "none"; }
				});
			};
			kind.addEventListener("change", toggle);
			toggle();
		}
		apply();

		// Optional AI button: writes the text from the subject, or improves the text already typed, in the chosen format.
		var ai = cfg.ai;
		if (ai) {
			var aiButton = field(ai.buttonId), aiMessage = field(ai.messageId), subject = field(ai.subjectId);
			var busy = false;
			var hasText = function () {
				if (format === "html") {
					// DOMParser builds an inert document: nothing in it loads or runs, unlike innerHTML on a live element.
					var doc = new DOMParser().parseFromString(getHtml(), "text/html");
					return ((doc.body && doc.body.textContent) || "").trim() !== "";
				}
				return plain.value.trim() !== "";
			};
			var refresh = function () {
				if (!ai.enabled) { return; }
				var isEmail = !kind || kind.value === "email";
				var filled = subject && subject.value.trim() !== "";
				aiButton.disabled = busy || !(isEmail && filled);
				aiButton.title = !isEmail ? ai.needEmail : (!filled ? ai.needSubject : "");
				if (!busy) { aiButton.value = hasText() ? ai.improve : ai.suggest; }
			};
			if (ai.enabled) {
				["input", "keyup", "change"].forEach(function (name) {
					if (subject) { subject.addEventListener(name, refresh); }
					plain.addEventListener(name, refresh);
				});
				if (kind) { kind.addEventListener("change", refresh); }
				Array.prototype.forEach.call(radios, function (radio) { radio.addEventListener("change", function () { window.setTimeout(refresh, 50); }); });
				tabHtml.addEventListener("click", refresh);
				tabPlain.addEventListener("click", refresh);
				if (window.CKEDITOR && window.CKEDITOR.on) {
					window.CKEDITOR.on("instanceReady", function (event) {
						if (event.editor.name === cfg.htmlName) { event.editor.on("change", refresh); refresh(); }
					});
				}
				aiButton.addEventListener("click", function () {
					if (aiButton.disabled) { return; }
					busy = true;
					aiButton.disabled = true;
					aiButton.value = ai.waiting;
					aiMessage.textContent = "";
					var existing = hasText() ? (format === "html" ? getHtml() : plain.value) : "";
					var body = new URLSearchParams({ token: cfg.token, action: "aitemplate", id: cfg.id, subject: subject.value, format: format, existing: existing });
					fetch(cfg.url, { method: "POST", body: body, credentials: "same-origin" })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							if (d.ok) {
								if (format === "html") { setHtml(d.text); } else { plain.value = d.text; }
							} else {
								aiMessage.textContent = d.error || ai.failed;
							}
						})
						.catch(function () { aiMessage.textContent = ai.failed; })
						.then(function () { busy = false; refresh(); });
				});
				refresh();
			}
		}
	}

	window.CrmFormatEditor = { init: init };
}());
