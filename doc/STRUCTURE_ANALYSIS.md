# CRM — structure analysis

Status: implemented in `/var/www/dolibarr-crm`. Module id 104801.

## Placement

Dolibarr has no separate plugin system. An external module is the plugin. Its source is its own project. This Dolibarr tree is not that project.

At runtime Dolibarr looks for modules in every directory listed in `$dolibarr_main_document_root_alt` (several paths, separated by a comma or a semicolon). When that value is empty, `htdocs/filefunc.inc.php` sets it to `htdocs/custom`. So the usual install path is:

```text
htdocs/custom/crm/core/modules/modCrm.class.php
```

`htdocs/custom` is the default install directory, not the only one, and not a place to commit this module into the Dolibarr repository. The development layout used here is flat. This directory is the module web root, not a nested `htdocs/` wrapper:

```text
/var/www/dolibarr-crm/          →  htdocs/custom/crm
```

`htdocs/custom/README.md` shows a nested `htdocs` symlink because that is how many Dolistore zips are packed. This project is the other shape: the repository root is what Dolibarr scans. A Dolistore zip (`module_crm-x.y.zip`) is the other way in: Home → Setup → Modules → Deploy an external module unpacks it into the same install path.

Core modules are different. Their pages live in `htdocs/<modulename>/` and their descriptors live in `htdocs/core/modules/modXxx.class.php`. CRM does not go there.

On this instance the two alt-root lines in `htdocs/conf/conf.php` are commented. The fallback above still points at `htdocs/custom`, so a module installed there is found. No file under `htdocs/core`, `htdocs/comm`, `htdocs/societe`, `htdocs/projet`, or `htdocs/ai` is modified.

Technical name `crm`: rights, `isModEnabled('crm')`, tables `llx_crm_*`, constants `CRM_*`, MCP tools named verb first with `crm` after the verb (`list_crm_stages`, `create_crm_opportunity`), because Dolibarr's tool access screen judges a tool by the first word of its name. Dolibarr already uses `crm` as a module family (the category commercial modules are filed under). No core module has this name, and no core table or constant uses those prefixes.

The paths in the file tree below are this directory. The symlink exposes them as `htdocs/custom/crm/`.

## What a deal is

A third party is the account. A contact is the person. An opportunity is the deal. A proposal is a price document on that deal. An agenda event is the next step or the record of a step already taken.

The module does not copy customers, contacts, proposals, or events into private tables.

| Object | Where it lives | Link |
| --- | --- | --- |
| Account | `llx_societe` | `llx_crm_opportunity.fk_soc` |
| Person we talk to | `llx_socpeople` | `llx_crm_opportunity.fk_socpeople` |
| Proposals | `llx_propal` | `llx_crm_opportunity_propal`, many per deal |
| Calls, meetings, tasks | `llx_actioncomm` | `elementtype = 'opportunity@crm'`, `fk_element` = deal id, plus `fk_soc` |

The expected amount stays on the deal. Linking a proposal shows its total beside that amount and does not overwrite it. One deal often has several proposals; a single `fk_propal` column hides that.

Agenda events use the existing types `AC_TEL`, `AC_RDV`, and `AC_OTH`. The module does not insert rows into `llx_c_actioncomm`. An e-mail uses `AC_EMAIL` (sent) or `AC_EMAIL_IN` (received) whether or not the type is active, as core does when it logs a message sent from a proposal or an invoice: `ActionComm` looks the type up by code, and the active flag only hides it from the manual pick-lists. It falls back to `AC_OTH` only if the type row is missing. Call and meeting still need an active type. Creating an event goes through `ActionComm`. The third party and the contact are set on the event, so it shows on the account and in the calendar, including CalDAV.

Disabling CRM does not delete those events. They are real history. The link back to the deal stops resolving while the module is off, and works again when it is turned back on.

## What changes relative to the screenshots

| Screenshot idea | Decision |
| --- | --- |
| Private activity table (call, meeting, e-mail, task, demo) | Dropped. A second calendar drifts from the agenda. |
| Probability edited on the deal and again on the stage | Probability belongs to the stage. Moving a card copies it. The deal does not have its own percentage. |
| Score rules such as "amount ≥ 20 000 → +30" | Dropped. Deal size is not buying intent. |
| Grade A–D as the thing on the card | Replaced by a short qualification checklist. The score is the count of confirmed items, shown as missing items first. |
| Funnel = deals sitting in each column today, divided by the first column | That is a stock snapshot, not a conversion rate. Reports use stage history. |
| One pipeline total and one weighted total | Forecast is split by month of expected close and by category: pipeline, upside, commit. |
| Free-text loss reason | A required reason from a dictionary. Empty reasons make the loss report useless. |
| Customer drip outreach as a primary feature, dry-run on | Kept, but off until an admin turns it on. The default mail is an internal reminder to the owner. |
| Custom application shell and its own sidebar | Dolibarr menus, standard cards and lists. One kanban page. |
| Company e-mail as the outreach recipient | The deal's primary contact. No contact address means no send. |

## Pages

Top menu **CRM**. Left menu entries come from the module descriptor.

| Page | Role |
| --- | --- |
| `index.php` | Work to do: no next step, overdue next step, expected close in the past, rotting. Then forecast for the coming months. |
| `pipeline.php` | Kanban for one pipeline, filtered by owner. Drag and drop moves the stage. |
| `opportunity_list.php` | Ref, title, account, contact, stage, amount, expected close, owner, next step date, qualification gaps |
| `opportunity_card.php` | Deal, linked proposals, qualification, next step, timeline of agenda events, win / loss |
| `account_history.php` | From v1.2.0. Full CRM history of one third party (stage moves and done events of its deals), linked from its card. |
| `activity_list.php` | A filtered view of `llx_actioncomm` for CRM deals. Not a second store. |
| `sequence_list.php`, `sequence_card.php` | Customer cadences, enrolments, journal |
| `report.php` | Forecast, cohort conversion, win rate, loss reasons, cycle time, by owner, by source |
| `admin/setup.php` | Parameters, pipelines and stages, qualification items, loss reasons, sources |

The home box lists the current user's overdue steps and deals with no next step.

## Third party card

From v1.2.0 the CRM shows itself on the Dolibarr third party card without patching core, through the hook context `thirdpartycard` (declared in `module_parts['hooks']`, so a re-enable is needed) in `ActionsCrm`:

- `formObjectOptions` adds a row "CRM opportunity" to the table of the card (core prints `resprints` in `extrafields_view.tpl.php`, view mode only; the method ignores `create`, `edit`, `update` and `add`). It lists up to three deals of the third party, open first, each linked to `opportunity_card.php`, with a "n more" link to the deal list, or, for a prospect without deal and a user who may write, a link to the creation form with `socid`.
- `showActionsLoadFicheTitre` prints the "CRM history" block. Core has no hook for the lower part of the card, and this one runs just before the core agenda box prints its title (`FormActions::showactions`) and lets core print it too (returns 0), so the block lands above that box. It prints once per request and only for `thirdpartycard`.

Both use `CrmAccountView` (reads only, no table): `fetchDeals()` and a timeline that is one `UNION ALL` of the stage history rows (`llx_crm_stage_history`) and the **done** agenda events of the deals (`elementtype = 'opportunity@crm'`, `percent >= 100`; planned events are not history), newest first, with `countTimeline()` for paging. Both apply `crmOwnerSql()` and the entity, like the rest of the module, whatever the agenda rights are. The full list is `account_history.php?socid=`, which needs the right to read third parties and refuses an external user. Decision: "history" is the union of stage moves and done events, not only the stage history table that the deal card calls History, because a salesperson looking at an account wants the exchanges too.

## Pipeline rules

Several pipelines (new business, renewal, partners), each with its own stages.

A stage has label, position, colour, probability, rotting delay, forecast category (`pipeline`, `upside`, `commit`), and a won or lost flag. Won is probability 100 and category closed. Lost is excluded from the forecast.

**The reply stage.** `llx_crm_stage.is_reply` marks, in each pipeline, the open stage a deal moves to when its prospect replies (`Stage::keepOneReplyStage()` keeps one per pipeline; a won or lost stage cannot be it). `Opportunity::moveToReplyStage()` is called where an answer is recorded: `CrmMailArchive::record()` for an incoming message, `Sequence::markReplied()` for the manual mark (page and MCP). It moves an open deal only when `crmStageComesBefore()` says its stage is before the reply stage (position, then id), writes the stage history, and does not apply `CRM_REQUIRE_NEXT_STEP`: it is the module reacting to an event, and the deal then appears as "no next step". The setting `CRM_REPLY_MOVES_DEAL` (default 1) turns it off. Schema step 3 adds the column and `crmEnsureReplyStage()` gives every pipeline that lacks one the stage "Replied to outreach" after its first open stage; the code works before the step has run (`crmStageReplySupported()`).

**Retiring instead of deleting.** Stages, qualification items, sources and loss reasons all have an `active` flag, edited in place in the Setup screen. A turned-off entry leaves the choice lists (`Stage::fetchAll($pipelineId, true)`, `Qualification::fetchItems()`, `crmFetchDictionary(..., true)`) but every read of an existing deal uses the unfiltered list, so a deal never loses the label of what it points at: a retired stage keeps its column on the board while a deal is in it, a retired source stays on the card. `Opportunity::moveToStage()` refuses a retired target (`CrmErrorStageInactive`). Deletion is refused when the record is referenced: a stage with deals or any stage history (the cohort report reads `llx_crm_stage_history`), a source or loss reason of a deal, a qualification item with answers. The user is then told to turn it off. Codes are unique per list (`crmCodeExists()`); `crmSaveDictionary()` and `crmSaveQualificationItem()` take `active = -1` to leave the flag of an existing row alone, which is what the screen and the MCP upsert tools use on update. Deleting an outreach step is confirmed and says how many running enrolments wait for it, because those end when their next step no longer exists.

Open deal hygiene, in order:

1. Every open deal should have a future agenda event. `CRM_REQUIRE_NEXT_STEP` defaults to on: a move between open stages is refused while no future event exists. Won and lost do not need a next step.
2. A deal is rotting when it is open and its latest done event (or its creation, if nothing was done) is older than the stage delay. Rotting is a warning, not a stage change.
3. An open deal whose expected close is in the past is a forecast error and is listed as such. The date is not rewritten by the module.

Closing lost requires a loss reason. Closing won asks for one and allows empty. Both write `date_close` and a stage-history row.

`llx_crm_stage_history` stores deal, from-stage, to-stage, user, date. Cohort conversion is computed from that table: of deals created in the chosen period, how many later entered each stage. The kanban column counts stay on the board, where they belong, and are not presented as a funnel.

Win rate is won / (won + lost) by close date in the period. Cycle time is the mean of (close date − creation) on won deals only.

## Sender of the e-mails written in the editor

`crmSenderChoices()` builds the choice list: `default` (`crmSender()`), `user` (the e-mail of the user) and `lead<id>` (`crmLeadCollectorMailboxes()`: active `emailcollector_emailcollector` rows with an active action of type `project`, or the reference `Collect_Leads`, whose `login` is an address). `email_compose.php` posts the code `sender`; `crmSenderChoiceFor()` takes the posted code, else the remembered one (`CRM_MAIL_FROM_CHOICE`, saved with `dol_set_user_param()` after a send or a manual registration), else the default; an unknown code is ignored. The address goes through `CrmEmailDraft::send()` and `crmSendMail()`, which refuses anything that is not an address, and is recorded as the sender of the event. The module reads the collector's setup but writes none of it and talks to no mailbox.

A hidden copy to the sender (`bccme`, ticked on the first display, kept as posted afterwards, offered only when the user's e-mail is valid) goes to `crmSendMail()` as `$copyToMe`. `crmCopyRecipients()` decides, in one place, who is in Cc and Bcc: the archive mailbox for a message about a deal (Cc or Bcc by `CRM_ARCHIVE_COPY`, as the administrator wrote it) and the sender's own address always as Bcc, once when it is the same address.

## Enrolling from the deal card

`opportunity_card.php` lists the enrolments of the deal (`Sequence::fetchEnrolmentsForOpportunity()`: the outreach, the status, the step it waits for) and, for a user with the right `sequence` on an open deal that follows nothing, a list of `Sequence::fetchEnrollable()` (active, at least one step) and the action `enroloutreach`, which calls `Sequence::enrolFromDeal()`. That method refuses an inactive outreach, a closed deal and a deal that already has a running enrolment (one outreach at a time on this screen), then calls `enrol()`, which stamps `date_enrol` with the moment of the call and schedules the first step from it. The rule "one at a time" is enforced here only: the outreach card and the MCP tool keep their own rules.

## Journal of the outreach

`Sequence::runEmailStep()` writes one `llx_crm_sequence_log` line for every e-mail step it runs, whatever the outcome, with the message as built by `buildEmail()` (the same method that makes what is sent): subject and text with the placeholders replaced (the values are escaped in an HTML text), format, recipient, sender (`crmSender()`, or the sender `crmSendMail()` used), the position of the step as a snapshot (the step can be edited or deleted later) and `content_source = 'recorded'`. The day of the outreach is not stored: it is `crmSequenceDay()` of the enrolment date and the date of the line, the same count `crmDueTimestamp()` uses for the delay of a step. `Sequence::fetchLog()` (one outreach) and `fetchLogForOpportunity()` (one deal) share `logSelect()`, which joins the enrolment, the outreach, the deal and the step, so every line says on what ground it exists.

`sequence_log_view.php` shows one line. It refuses a line whose deal owner the user may not read (`crmCanReadOwner()`), and renders an HTML text through `dol_htmlwithnojs()`, a plain text escaped. The journal of an outreach and the History of a deal link to it. A line without content (written before schema 2) is completed by `Sequence::ensureLogContent()` when opened, and saved: a sent line takes the message recorded on the deal (an `AC_EMAIL` event of the same deal, same subject, within ten minutes, source `event`), otherwise the text is rebuilt from the step and the deal as they are now (source `rebuilt`, flagged on the page). A line whose step is gone stays empty. The code works before the schema step has run (`crmLogContentSupported()`): the old columns are written, and the page says the content is not kept yet.

## Next steps and lists

A planned step can be changed (date, and for a call, meeting or task its kind and title) and deleted from the deal card and by MCP, through `CrmActivity::updateStep()` and `deleteStep()`. Both load the event with `loadStep()`, which refuses an event that is not linked to a deal, whose deal the user may not read (`crmCanReadOwner()`) or that belongs to another deal than the page's; `complete()` goes through it too. A done step is history (the e-mail thread, the stage timeline, the "latest done" used by rotting) and is refused. A planned e-mail keeps its subject and text, which the e-mail editor owns: only its date moves. Delete uses `ActionComm::delete()`, so core hooks and triggers run.

The deal list and the activity list sort on a whitelist of columns (`Opportunity::SORT_COLUMNS`, the four columns of `CrmActivity::fetchList()`; an unknown name sorts on the default) and page with Dolibarr's `print_barre_liste()` (`sortfield`, `sortorder`, `page`, `limit`, default limit from the user's setting). The count and the rows share one FROM/WHERE (`listFromWhere()`), so the owner restriction cannot differ between them.

## Qualification

`llx_crm_qualification` is a short checklist the admin can edit. Seed, in order:

| Code | Label | Points |
| --- | --- | --- |
| `NEED` | Need confirmed | 25 |
| `BUDGET` | Budget confirmed | 25 |
| `DECISION` | Decision maker identified | 25 |
| `TIMELINE` | Timeline confirmed | 25 |

Answers live in `llx_crm_opportunity_qualification` (yes/no, date, user). The card lists what is still missing. The numeric score is the sum of the points of the yes answers, capped at 100. There is no generic operator engine and no grade badge.

## Automated outreach and owner reminders

Two jobs, registered in `$this->cronjobs`, run by Dolibarr cron. Each returns immediately when the module is disabled. Suggested frequency: hourly. Cap: `CRM_MAX_EMAILS_PER_RUN` (50).

**Owner reminders** (`CrmCron::runOwnerReminders`). On by default.

One message per owner per day. It lists overdue next steps, deals with no next step, and open deals whose expected close is in the past. `CRM_REMINDER_DRY_RUN` defaults to on, so the first runs only fill `llx_crm_reminder_log` (owner, date, status, error). A row for that owner and that day blocks a second message.

**Automated outreach** (`CrmCron::runSequences`). Off by default (`CRM_SEQUENCES_ON` = 0).

An outreach is an explicit cadence on a deal, not a blast to every prospect. Steps are `email`, `call`, or `task`, delayed D+n from enrolment.

- **E-mail steps have two modes**, set globally (`CRM_SEQUENCE_EMAIL_MODE`) and overridable per step (`llx_crm_sequence_step.send_mode`: empty follows the global setting, `draft` or `send` forces it; `crmStepDraftMode()`). `draft` (default, and the behaviour for any value other than `send`): the step creates a planned e-mail event on the deal, owned by the deal owner, with the subject as label (deal reference appended in square brackets) and the suggested text as note, and the journal row is `draft`. Nothing is sent, dry-run does not apply, and drafts do not count toward `CRM_MAX_EMAILS_PER_RUN`. See "E-mail drafts". `send`: the cron sends as described below.
- In send mode, e-mail goes to `fk_socpeople` on the deal. No address: log `error` and do not spin. The company address is not a fallback.
- Placeholders `__DEAL__`, `__REF__`, `__AMOUNT__`, `__CONTACT__`, `__CUSTOMER__`. An unknown token is left as written.
- `call` and `task` create an agenda event for the owner. They do not send mail.
- A real send uses `CMailFile` and then logs an agenda event.
- Stop when the deal is won or lost, when the enrolment is stopped, when someone marks "prospect replied" (card or MCP), or when the e-mail collector records an answer from the customer on the deal (see "E-mail history").
- `CRM_DRY_RUN` defaults to on even after outreach are enabled. Simulated rows advance the enrolment so the cadence can be checked. The outreach screen shows that banner.
- Sender is `CRM_MAIL_FROM`, or the company sender when that constant is empty.

## E-mail drafts

A draft is the planned agenda event itself (`AC_EMAIL`, `percent` < 100): label = subject, note = suggested text. It counts as a next step, shows on the deal card, in Activities and in the home "Overdue" list once due, each with a "Write / send" link to `email_compose.php`. A planned e-mail created by hand with the "E-mail" kind of the next-step form is the same object, with an empty text. The page lets the salesperson edit the To, subject and text, then:

- **Save draft**: keeps the edits (`CrmEmailDraft::save`).
- **Send from here**: confirmation, then `crmSendMail()` with the deal tracking id and the archive copy; the draft is replaced by a done `AC_EMAIL` event holding the full text and the Message-ID.
- **Open in my mail client** / **Copy text**: a `mailto:` link built in the browser from the current fields, with the archive address as Cc or Bcc, or the text on the clipboard. The salesperson keeps the subject, which carries the deal reference, and keeps the archive address in copy.
- **E-mail sent manually**: confirmation, then the draft is replaced by a done `AC_EMAIL` event with no Message-ID, which marks "sent, waiting for the importer".
- **Discard**: deletes the draft.

Replacing means "create the done event, then delete the draft": `ActionComm::update` does not write the e-mail columns, so the module never updates them in place and never writes them by SQL.

When the collector later reads the copy, `CrmMailArchive::record()` recognises the message by the deal reference in the subject, and replaces the pending event instead of adding a second one. The pending event is, in this order of preference: a done e-mail event of the deal with no Message-ID (registered by hand, last 30 days) whose subject matches, else the oldest such event; or a planned e-mail event of the deal whose subject matches, which covers a message sent from a mail client without pressing the button. A subject matches when it is equal once `Re:`/`Fwd:` prefixes, case and spacing are ignored. Only outgoing messages are matched this way.

## HTML e-mail

`email_compose.php` sends plain text or HTML. The format is chosen with a radio (`format`) and, for a saved draft, detected from the note (`crmLooksLikeHtml`). The HTML tab is Dolibarr's `DolEditor` (CKEditor, toolbar `dolibarr_mailings`); its content is read with the `restricthtml` filter. The message exists in one form only: the plain version of an HTML message is derived, never written separately, because `CMailFile` builds the alternative part itself (swiftmailer: `addPart(strip_tags)`; mail and smtps methods: the alternative is added when `MAIN_MAIL_USE_MULTI_PART` is set, which Dolibarr turns on by default and `crmSendMail()` also sets in memory for an HTML message and restores) and has no parameter for a hand-written one. `crmHtmlForMail()` adds a line feed after block ends and `<br>`, invisible in HTML, so that the stripped alternative keeps its paragraphs; `crmHtmlToText()` produces the same text for the "plain-text version" tab, through the ajax action `previewtext` of the page (it defines `NOTOKENRENEWAL` first, so the open form keeps its CSRF token). `crmSendMail(..., $html = true)` calls `CMailFile` with `msgishtml = 1`. The note of the sent or registered event holds the HTML. Readers of event notes that need text (the AI assistant) convert with `crmHtmlToText()`.

## AI text for an outreach step

The add-step form of `sequence_card.php` has an AI button, enabled by `crm-format-editor` only for an e-mail step with a subject, and rendered disabled by the server unless `CrmAiReply::isAvailable()` (AI module on, `CRM_AI_EMAIL_ASSIST` on), `CrmAiReply::isConfigured()` (a key, or an address for the custom service) and the "Manage automated outreach" right hold. The ajax action `aitemplate` calls `CrmAiReply::suggestStepTemplate()`: the prompt `CrmAiStepPrompt`, the subject, the five placeholders and what they mean, then either the current text to improve or a request for a new template, then the output rules for the chosen format (HTML fragment or plain text). Only the subject and the template go out, never deal data. The answer is cleaned (`dol_htmlwithnojs` for HTML) and replaces the text box; nothing is saved by the action.

## AI reply suggestions

`email_compose.php` offers, when the AI module is on and an administrator has set `CRM_AI_EMAIL_ASSIST` to 1 (it is 0 by default), a block with a pre-recorded prompt the salesperson can edit and a button. The action `aisuggest` calls `CrmAiReply::suggest()`, which builds one text (the prompt, a deal summary, the latest 10 e-mails of the deal oldest first with quoted lines removed, each at most 1500 characters and 10 000 in all with the oldest dropped first, the current draft, output rules) and hands it to Dolibarr's `Ai::generateContent()` with the function `textgenerationemail`. The module has no AI client: provider, key, model, custom pre-prompt and its other settings are the AI module's. When `AI_PRIVACY_REDACTION` is on, the text is masked with the AI module's `PrivacyGuard` (including the account, contact and owner names) and the answer is unmasked. The answer is cleaned to plain text and put in the text box of the page; nothing is saved or sent by this action. Both AI calls (`suggest()` and `suggestStepTemplate()`) go through `CrmAiReply::runWithTimeLimit()`: Dolibarr's `getURLContent()` gives an outgoing call 30 seconds (`MAIN_USE_RESPONSE_TIMEOUT`), so for the length of the call that constant is raised in memory to `CRM_AI_TIMEOUT` (`crmAiTimeoutSeconds()`, default 120, 10 to 600, never below the administrator's own value), restored in a `finally`, and the PHP time limit is raised if lower. No core setting is written. A failure whose message is "No http received" is rewritten with the elapsed seconds and the limit, as a timeout or a network failure (`crmAiNoHttpKind()`), because the core's `Ai` class drops the curl error. The default prompt is `CRM_AI_EMAIL_PROMPT`, else the language key `CrmAiDefaultPrompt`. The thread is read from the done `AC_EMAIL` / `AC_EMAIL_IN` events of the deal (`CrmActivity::fetchEmailThread`), so what the AI sees is what the e-mail history recorded.

## E-mail history

The full exchange with a customer is kept as agenda events on the deal, not in a table of the module. A message is one done event (`percent` 100, so it is never counted as a next step) with `elementtype = 'opportunity@crm'`, the plain-text body in the note, and Dolibarr's own e-mail columns (`email_msgid`, from, to, cc, subject). Code `AC_EMAIL` for a message we sent, `AC_EMAIL_IN` for one we received; the event type is chosen by `CrmActivity::typeCode()` (the same code, `AC_EMAIL` or `AC_EMAIL_IN`).

Sending. Every customer e-mail sent for a deal goes through `crmSendMail($to, $subject, $body, $dealId)`. It gives the message the tracking id `crm<dealId>`, so the Message-ID is `<unique>.<transport>-dolibarr-crm<dealId>@<host>`, and copies it to `CRM_ARCHIVE_MAILBOX` as Bcc (default) or Cc (`CRM_ARCHIVE_COPY`). The event is written at send time with that Message-ID. Owner reminders carry no deal, no tracking id and no copy.

Reading. The module has no IMAP code. The Dolibarr **e-mail collector** (core module) reads the archive mailbox. The module adds an operation to the collector, "CRM: record the e-mail on its deal" (`hookcrmrecordemail`), through the hooks `addMoreActionsEmailCollector` (context `emailcollectorcard`) and `doCollectImapOneCollector` (context `emailcollector`), both in `ActionsCrm`. The operation goes to `CrmMailArchive::record()`. A message is kept only when:

1. its Message-ID, In-Reply-To or References carries `dolibarr-crm<dealId>@` (the copy of what the module sent, or an answer that threads on it); or
1b. its subject carries a deal reference in square brackets, `[OPP2026-0001]`, which the module puts in the subject of every draft, so a message written in a mail client from a draft, and the customer's reply to it, are recognised even when the threading headers are lost; or
2. one of those ids is the Message-ID of a message already recorded on a deal. This catches a salesperson who answers from their own mail client, quoting the exchange, with the archive mailbox in copy.
1c. **From v1.2.0**, none of those, but the other end is the contact (`fk_socpeople`) of an open deal: the sender of a received message, or a To/Cc recipient of a message sent by us (`CrmMailArchive::findDealByContact()`, the direction being known first from the sender). Exactly one open deal must match: with two or more the message is ignored rather than attached to a guess, and the addresses of the module sender and of users never match. The known-address, authentication and automatic-mail rules below still apply (the contact is a known address by construction). Only the deal contact counts, not the other contacts or the third party address: a wider rule would attach a mail from any colleague of the account to whichever deal.

Anything else is ignored without error, so the mailbox is not a general inbox and the other operations of the collector are not disturbed. A message already recorded (same Message-ID) is skipped, which is how the Bcc copy of a module e-mail does not create a second event. The direction is "sent" when the sender is the module sender, an address in `MAIL_FROM_EMAILS_TO_CONSIDER_SENDING`, or a Dolibarr user's e-mail, otherwise "received". A received message stops the running enrolments of the deal whose outreach has `stop_on_reply`.

Limits. A message composed from scratch, with no thread to a recorded one, cannot be tied to a deal unless, from v1.2.0, its other end is the contact of exactly one open deal. A Bcc copy is not in the customer's "reply all", so their answer reaches the mailbox only if a person copies or forwards it; use Cc if the customer's replies should be seen directly. The note holds the plain-text body: attachments are not stored. Events follow the agenda's visibility rules, not the CRM's.

## Data the module owns

One file, `sql/llx_crm.sql`. Indexes are inside each `CREATE TABLE`. There is no `IF NOT EXISTS` and no separate `.key.sql`. No `ALTER` on a core table and no extrafield. Every table has `entity` except `llx_crm_sequence_step`, which belongs to its outreach.

| Table | Holds |
| --- | --- |
| `llx_crm_pipeline` | `ref`, `label`, `active` |
| `llx_crm_stage` | Pipeline, label, position, colour, probability, rotting days, forecast category, won flag, lost flag |
| `llx_crm_source` | Lead source dictionary |
| `llx_crm_loss_reason` | Loss reason dictionary |
| `llx_crm_opportunity` | Deal. See below |
| `llx_crm_stage_history` | From stage, to stage, user, date |
| `llx_crm_opportunity_propal` | Deal ↔ proposal |
| `llx_crm_qualification` | Checklist definition |
| `llx_crm_opportunity_qualification` | Answers |
| `llx_crm_sequence` | Label, active, stop flags |
| `llx_crm_sequence_step` | Position, action, delay days, subject, body (plain text or HTML, recognised by `crmLooksLikeHtml`), send mode |
| `llx_crm_sequence_enrol` | Deal, current step, next run, status, stop reason |
| `llx_crm_sequence_log` | One line per e-mail step run: recipient, sender, subject, the text with the placeholders replaced and its format, step position, date, `sent` / `simulated` / `draft` / `error`, and where the text comes from (`recorded` when made, `event` or `rebuilt` for a line completed later) |
| `llx_crm_reminder_log` | Owner reminder journal |

Opportunity columns: `ref` (`OPP` + year + outreach), `label`, `fk_soc`, `fk_socpeople`, `fk_pipeline`, `fk_stage`, `amount`, `date_close_expected`, `fk_source`, `fk_user_resp`, `description`, `status` (`open` / `won` / `lost`), `date_close`, `fk_loss_reason`, `loss_note`, `datec`, `tms`, `fk_user_creat`. Probability is not a column.

Seed, only when that entity has no pipeline yet: one pipeline, seven stages (New 10% pipeline, Replied to outreach 15% pipeline (the reply stage), Qualified 25% pipeline, Proposal sent 50% upside, Negotiation 75% commit, Won 100% won, Lost 0% lost), six sources (website, referral, inbound call, event, partner, prospecting), the four qualification items, and loss reasons (price, no budget, competitor, timing, no decision, no answer). Re-enabling does not insert them again.

Constants: `CRM_ROTTING_DAYS` (15, default for a new stage), `CRM_FORECAST_MONTHS` (3), `CRM_MAX_EMAILS_PER_RUN` (50), `CRM_MAIL_FROM`, `CRM_SEQUENCES_ON` (0), `CRM_SEQUENCE_EMAIL_MODE` (`draft`), `CRM_DRY_RUN` (1), `CRM_REMINDERS_ON` (1), `CRM_REMINDER_DRY_RUN` (1), `CRM_REQUIRE_NEXT_STEP` (1), `CRM_ARCHIVE_MAILBOX` (empty: no copy), `CRM_ARCHIVE_COPY` (`bcc` or `cc`), `MAIN_MODULE_CRM_ICON` (read by the theme).

## MCP

Compatibility: the tools run on the Dolibarr 24.0.0 core and on 25. Dolibarr 24.0.0 checks no declared rights and has no write confirmation, so every tool starts with `CrmMcpTool::denyMissingRights()`, which applies `getRequiredRights()` itself; on 25 it is a second check with the same result. `PrivacyGuard::maskNames()` does not exist in 24.0.0: `CrmAiReply` masks the names itself when the method is missing.

`McpHandler` loads external tools through the hook `addMcpTools` in context `aimcp`. The module returns `McpTool` instances. Nothing is added under `htdocs/ai/tools/`.

Screens work with the AI module off. Tools appear when it is on, and they pass through `AI_MCP_SERVER_ALLOWED_TOOLS`. Write tools return `writeConfirmationPreview()`. Read tools return `NO_WRITE`. `getRequiredRights()` matches the permissions below. Without `readall`, every tool only sees deals owned by the caller.

Used as they already exist:

| Need | Core tool |
| --- | --- |
| Account and contacts | `search_thirdparties`, `get_thirdparty_details`, `create_thirdparty`, `update_thirdparty`, `list_thirdparty_contacts`, `add_thirdparty_contact` |
| Proposals | proposal operations in `htdocs/ai/tools/crud_objects.class.php` |
| Company sales outside the pipeline | `get_sales_report` |

CRM tools:

| Tool | Writes |
| --- | --- |
| `list_crm_pipelines`, `list_crm_stages`, `list_crm_sources`, `list_crm_loss_reasons`, `list_crm_qualifications` | no |
| `create_or_update_crm_pipeline`, `create_or_update_crm_stage`, `delete_crm_stage`, `create_or_update_crm_source`, `create_or_update_crm_loss_reason`, `create_or_update_crm_qualification` | yes |
| `list_crm_opportunities`, `get_crm_opportunity`, `list_crm_stage_history` | no |
| `create_crm_opportunity`, `update_crm_opportunity`, `change_crm_opportunity_stage` | yes |
| `change_crm_opportunity_to_won`, `change_crm_opportunity_to_lost`, `delete_crm_opportunity` | yes |
| `add_crm_proposal_link`, `remove_crm_proposal_link`, `update_crm_qualification_answers` | yes |
| `list_crm_activities` | no |
| `create_crm_activity`, `change_crm_activity_to_done`, `update_crm_activity`, `delete_crm_activity` | yes, agenda events |
| `list_crm_sequences`, `get_crm_sequence` | no |
| `create_crm_sequence`, `update_crm_sequence`, `add_crm_sequence_step`, `update_crm_sequence_step`, `delete_crm_sequence_step` | yes |
| `add_crm_sequence_enrolment`, `change_crm_sequence_enrolment_to_stopped`, `update_crm_opportunity_replied` | yes |
| `get_crm_dashboard`, `get_crm_forecast`, `get_crm_conversion`, `get_crm_loss_reasons` | no |
| `get_crm_win_rate`, `get_crm_cycle_time`, `get_crm_by_owner`, `get_crm_by_source` | no |
| `get_crm_settings` | no |
| `send_crm_due_sequence_steps`, `send_crm_owner_reminders` | yes |

There is no core tool to search users. Owner and assignee arguments are numeric user ids. There is no `list_crm_users`.

## Permissions

`$user->hasRight('crm', ...)`.

| Permission | Effect |
| --- | --- |
| `read` | Own deals, own pipeline, own activities |
| `write` | Create and edit deals, move stages, qualify, link proposals |
| `delete` | Delete deals |
| `readall` | Every owner's deals, in the UI and in MCP |
| `activity` | Create and complete agenda events for a deal |
| `sequence` | Manage outreach and enrolments |
| `report` | Reports and the report tools |
| `setup` | Constants, stages, qualification, dictionaries |

## Install and removal

`init()` loads `sql/`, runs the schema steps newer than `CRM_SCHEMA_VERSION` (`crmUpgradeSchema()`: ordered, one by one, the number recorded after each success, stopping at the first failure, which makes `init()` fail with its reason), seeds only an empty entity, and registers menus, rights, the home box, and both cron jobs. Dolibarr keeps no version for an external module and notices no update, so the schema version is the module's own: `crmSchemaCurrent()` is what the code expects, `CRM_SCHEMA_VERSION` what the database reached, and `crmSchemaWarning()` tells an administrator on the home page and the setup screen when the code is ahead. A step is safe to run on a database that already has its result. See `UPDATE.md`.

`remove()` unregisters menus, rights, the box, and the cron jobs. It does not `DROP` `llx_crm_*`, does not delete `llx_actioncomm` rows, and does not touch `llx_societe`, `llx_socpeople`, `llx_propal`, or `llx_user`. Re-enable finds the deals again.

No purge button in v1. A later action can drop only `llx_crm_*` and `CRM_*` constants after a typed confirmation. Agenda events stay even then, unless that later action says otherwise.

## File tree

```text
/var/www/dolibarr-crm/                 # symlinked to htdocs/custom/crm
├── core/
│   ├── modules/modCrm.class.php       # modCrm, id 104801
│   └── boxes/crmwidget.php
├── class/
│   ├── actions_crm.class.php          # addMcpTools, e-mail collector operation, search entry, third party card (v1.2.0)
│   ├── crmaccountview.class.php       # deals and CRM history of a third party (v1.2.0)
│   ├── crmmailarchive.class.php       # e-mail to deal matching and recording
│   ├── crmemaildraft.class.php        # review, send, register or discard a planned e-mail
│   ├── crmaireply.class.php           # prompt and call for an AI-proposed reply
│   ├── opportunity.class.php
│   ├── pipeline.class.php
│   ├── stage.class.php
│   ├── crmactivity.class.php
│   ├── qualification.class.php
│   ├── outreach.class.php
│   ├── crmcron.class.php
│   ├── crmreport.class.php
│   └── mcp/
│       ├── crmmcptool.class.php
│       ├── crmdictionarytool.class.php
│       ├── crmopportunitytool.class.php
│       ├── crmactivitytool.class.php
│       ├── crmsequencetool.class.php
│       └── crmreporttool.class.php
├── admin/setup.php
├── index.php
├── pipeline.php
├── opportunity_list.php
├── opportunity_card.php
├── activity_list.php
├── email_compose.php
├── sequence_list.php
├── sequence_card.php
├── report.php
├── lib/
│   ├── crm.lib.php
│   ├── crmlogic.lib.php               # pure rules, no Dolibarr
│   └── crm_bootstrap.inc.php
├── js/pipeline.js
├── css/crm.css
├── sql/llx_crm.sql
├── langs/en_US/crm.lang
├── langs/fr_FR/crm.lang
├── test/phpunit/CrmLogicTest.php
├── test/cli/                            (checks needing Dolibarr and a database, rolled back)
├── README.md
├── INSTALL.md
├── UPDATE.md
├── ChangeLog.md
└── doc/
    ├── ADMIN_GUIDE.md
    ├── USER_GUIDE.md
    └── STRUCTURE_ANALYSIS.md
```

Module id: 104801. Rights ids 10480101–10480108.

PHP 8.1+, `declare(strict_types=1)`, tabs. Pages keep actions and view apart. Stage moves, closing, qualification, sending, and reminders live in the classes, so the pages, the cron, and the MCP tools call the same methods.

Kanban drag and drop posts to `pipeline.php` with a CSRF token. No third-party script.

## Out of scope

- Core patches, extrafields, and new agenda event types.
- A mailbox connector of our own. Reading the archive mailbox is done by the Dolibarr e-mail collector (see "E-mail history"). Mail that is not sent by the module and does not quote an exchange already recorded is not archived.
- Reviewing the machine translations of the other languages.
- A REST API beside MCP.
- Importing existing proposals into deals.
- A generic scoring-rule engine.
- Multi-currency amounts. The amount is the company currency, same as a proposal with multicurrency left off. Revisit if this instance relies on multicurrency.

## Security design

Rules the code follows, so that a change keeps them:

1. **One token rule.** A page calls `crmRequireToken($action, $displayActions)` right after reading its action: only pure display actions (empty, view, create, edit, the edit and confirm forms) may arrive without the token. The e-mail editor reads its `act_*` buttons from a POST only. `define('CSRFCHECK_WITH_TOKEN')` is not used, because it would break the token-less links to `action=create`.
2. **Visibility is a query.** Lists and detail queries of outreach (`fetchEnrolments`, `fetchLog`, `enrolmentVisible`) take the user and add `crmOwnerSql()` and the entity; a bare rowid from a request is never enough.
3. **Rules live in the class.** `Sequence::enrol()`, `Opportunity::moveToStage()`, `validateLinks()`, `linkProposal()` and `Stage::validate()` hold the business rules, so the web pages and the MCP tools cannot differ.
4. **Output is escaped at the sink; input is cleaned at the door.** Labels are escaped where they are printed (titles, confirmations are the easy ones to forget: `trans()` keeps markup). Single-line fields go through `crmSingleLine()` (no control characters, bounded), descriptions and notes lose their markup, e-mail HTML goes through `dol_htmlwithnojs()`. Both sides, because the MCP tools write data the pages read.
5. **Errors are keys.** `crmDbError()` logs the database message and returns a language key; `crmErrorText()` shows a raw message only to an administrator, escaped.
6. **Inbound mail is evidence, not authority.** `CrmMailArchive::record()` needs a known address of the deal, and skips the side effects (stopping the outreach, moving the deal) for automatic mail. Pure helpers (`crmSenderTrusted`, `crmIsAutomaticMail`, `crmMailAuthPassed`, `crmClampMailDate`) live in `crmlogic.lib.php` and are unit tested.
7. **Settings have one validator**, `crmNormalizeSetting()`, used by the setup page and the MCP tool.
8. **The job claims before it works.** `Sequence::claim()` moves the due date one hour ahead with a conditional UPDATE; a run that loses the race skips the row.

Residual risks, accepted and documented: a forged sender from a known domain on a mail server that does not verify senders; the journal and the archived e-mails keep personal data until someone purges them; the owner reminder job can send a duplicate when two runs overlap; remote images in an HTML e-mail are loaded when a journal entry is opened.

## Decisions kept

1. Automated outreach ship disabled, with dry-run on, and their e-mail steps are drafts for the salesperson, not automatic sends. Owner reminders ship enabled, also dry-run until that is turned off.
2. Calls and meetings are agenda events and are kept after the module is disabled.
3. An open deal cannot change stage until it has a future next step (`CRM_REQUIRE_NEXT_STEP`, default on).
4. Owners in MCP are numeric user ids. There is no `list_crm_users`.
5. The module lives in `/var/www/dolibarr-crm` and is reached by the `htdocs/custom/crm` symlink. The empty alternate-root fallback already scans `htdocs/custom`, so the two `conf.php` lines stay commented.
