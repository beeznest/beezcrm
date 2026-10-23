# CHANGELOG MODULE CRM FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

The version of the module is the `$this->version` property of `core/modules/modCrm.class.php`. This file is shown by Dolibarr on the help page of the module (Home → Setup → Modules, help icon of the CRM line). Newest version first. A change a user or an administrator can notice goes here, in the same change.

## 1.2.0 (unreleased)

This version improves the salesperson's workflow and connects the CRM deals to the prospects and customers (third parties, "tiers") of Dolibarr. Date it when it is released.

Documentation rule for this version: whatever changes an interface or the data is added to `doc/USER_GUIDE.md`, `doc/ADMIN_GUIDE.md` and `doc/STRUCTURE_ANALYSIS.md` marked **From v1.2.0**, so the guides do not read as if it had always been there. Each change is listed below in the same change that builds it.

### Added

- **Internal notes instead of the description.** The deal card has a section **Internal notes**: the notes are in chronological order, right under the data of the deal: only the latest is shown, and the link **Show the n earlier notes** unfolds the others above it. The form to add a note is below the list. Each note keeps its author, its date and time and its text. A note can be **edited** later (by its author, or by a user who reads every owner's deals); every earlier text is kept, with who wrote it and when, under a **history** link. Files can be attached to a note (several at once; the Dolibarr document size limit, at most 10 per note), downloaded, and previewed when they are PDF; a **paperclip** next to the title opens the list of all the files of the deal with their size, date, author and note. Files are stored in the Dolibarr documents directory under `crm/opportunity/<deal id>/` (back it up) and are served only to users who may read the deal. The existing descriptions become the first note of their deal. New MCP tools: `list_crm_opportunity_notes`, `list_crm_opportunity_files`, `list_crm_note_versions`, `add_crm_opportunity_note`, `update_crm_note`, `add_crm_note_file`, `delete_crm_note_file`; `get_crm_opportunity` returns `notes` instead of `description`, `create_crm_opportunity` puts its `description` in the first note, `update_crm_opportunity` no longer takes one. The AI reply prompt sends the latest three notes. **Schema version 4** (three new tables, the descriptions copied to notes): disable and re-enable the module after the update, see `UPDATE.md`. New check: `test/cli/notes_check.php`.
- **The CRM on the Prospect tab of a third party.** The **Prospect** tab of a third party (`comm/card.php`, not the **Third party** tab) shows a line **CRM opportunity** in its table of data, with a link to each of its deals in the CRM (three at most, open first), or a **New opportunity** link for a prospect that has none. At the bottom of the right column, a block **CRM history** lists the last 5 events of its deals (stage moves, done steps and e-mails), with a link **Full CRM history** to a new page (`account_history.php`). Only deals the user may read are shown; nothing shows when the module is off. New hook context `thirdpartycomm`: **disable and re-enable the module** after the update. No schema change, no new right. New check: `test/cli/account_view_check.php`.
- **E-mails to or from the deal contact are recorded without a marker.** When the archive (lead collection) mailbox receives a copy, in Cc or Bcc, of a message with no tracking id, thread or `[OPP…]` reference in its subject, it is now recorded in the history of the deal if the other end is clearly the contact of that deal: the sender of a received message, or a To/Cc recipient of a message sent by us, has the e-mail address of the deal contact. Only open deals are considered, and when the contact has several open deals nothing is attached (use the reference in the subject). The sender, authentication and automatic-mail checks of 1.1.0 still apply, so a received message from the contact also stops the outreach and moves the deal to the reply stage. No setting, no schema change. New check: `test/cli/mail_contact_match_check.php`.

### Fixed

- **"Propose a reply with AI" gave a 500 error** (`Call to undefined function dol_set_user_param()`) on installations where Dolibarr had not already loaded `core/lib/functions2.lib.php` for the request. The AI call counter and the remembered sender choice of the e-mail editor now load that file themselves.

### Changed

- (nothing yet)

## 1.1.0 (2026-10-18)

### Security

A review against the OWASP Top 10 led to these changes. Nothing in the data model changes.

- **CSRF.** Every action of every page now needs the token of the session, not only the actions Dolibarr's own check sees. A link such as `email_compose.php?act_send=1` no longer sends anything: the buttons of the e-mail editor are read from a POST only.
- **Who sees what.** The enrolments and the journal of an automated outreach are limited to the deals the user may read (own deals, or all with "Read all"), and to the current entity. Stopping an enrolment or marking a reply checks the deal too; marking a reply moves the deal only for a user who may write.
- **Enrolment rules in one place.** `Sequence::enrol()` now refuses an inactive outreach, a deal that does not exist, a closed deal and a deal that already follows an outreach, whichever entry is used (deal card, outreach card, MCP).
- **Inbound e-mail is no longer trusted blindly.** A message found by a deal reference or a Message-ID is kept only if its sender (received) or one of its recipients (sent by us) is an address the deal knows: its contact, the other contacts and the address of its third party, or a colleague at their company domain (public mail domains such as gmail.com do not count). A message that our mail server reports as failing SPF/DKIM/DMARC is ignored; the new setting **"Archive only authenticated e-mails"** (`CRM_ARCHIVE_REQUIRE_AUTH`, off by default) also ignores a message without a passed check. An automatic message (bounce, out-of-office, list) is kept but no longer stops the outreach or moves the deal. Dates are kept within a believable range and long headers are cut.
- **Stored XSS.** Titles and confirmation texts that print a deal, outreach, stage or list label are escaped; the MCP tools strip markup and line breaks from labels, references, subjects and codes, strip markup from descriptions and notes, and clean the HTML of an e-mail step; the web forms apply the same rules when saving. The editor's "has text" test no longer builds live HTML.
- **Mail header injection.** The subject, recipient and sender of every e-mail are made single-line before they are sent, and the sender and archive addresses are validated when saved.
- **Links of a deal are validated**: the third party and the contact must exist in the entity and the contact must belong to the third party; a proposal can be linked only if it exists, the user may read proposals and it belongs to the third party of the deal. A closed deal can no longer be reopened without a next step when that rule is on.
- **Settings are validated** on the setup page (switches 0/1, bounded numbers, known modes, one valid address, bounded AI prompt), all values before any write. The MCP tool `update_crm_settings` is **removed**: an AI assistant can read the settings (`get_crm_settings`) but cannot change them, since a setting such as dry-run or the sender decides whether real e-mail leaves. Stage values are validated too (colour `#rrggbb`, probability 0–100, forecast category); the MCP stage tool no longer lets a stage change pipeline.
- **AI.** A per-user limit of suggestions per hour (`CRM_AI_MAX_PER_HOUR`, default 30), a cap on the prompt, and a sentence telling the model that the e-mails and notes it reads are data, not instructions.
- **Errors.** A database or provider error is written to the Dolibarr log and an ordinary user sees a generic message; an administrator sees the text, escaped.
- **Concurrency.** The job that sends the outreach claims each enrolment before working on it, so two runs at the same time no longer send the same e-mail twice.
- **Other.** Lists are capped at 500 rows; entity filters were added to the journal and the next-step queries; the class and library files refuse a direct request; a `.htaccess` hides `.git`, `doc`, `test`, `sql` and the other source files on Apache (see INSTALL.md for nginx and production); the inline script of the deal card carries the CSP nonce.

### Automated outreach

- The **deal card** has a section "Automated outreach": the outreach the lead is enrolled in with its status (running, stopped with the reason, done), the step it waits for and when it falls due. A lead that follows none, on an open deal, shows a list of the outreach that can be used (active, with at least one step) and the button "Enrol from now": the enrolment starts at the moment it is saved, the first step is due after its delay counted from then. A lead follows one outreach at a time; it needs the right "Manage automated outreach" to enrol.

### Prospects who reply

- A new stage, **Replied to outreach** (shown in French as "Répondu à approche"), between New and Qualified in the standard pipeline (15%, forecast category `pipeline`). It is the **reply stage** of its pipeline, marked by the new checkbox "Reply stage" of the stage form (one per pipeline; it must be an open stage; copying a pipeline copies the mark).
- When the answer of a prospect is recorded on an open deal, the deal **moves by itself to the reply stage** of its pipeline: when the e-mail collector records an incoming message on the deal, and when someone presses "Mark replied" on an enrolment of an automated outreach (screen or MCP tool `update_crm_opportunity_replied`, which now also moves the deal). The deal moves only when it is still before that stage: one that is already there or beyond it, or won or lost, stays. The move is written in the stage history, and the rule "a next step is required to change stage" does not apply to it. The salesperson then qualifies the deal.
- The setting **"Move a deal to the reply stage when the prospect replies"** (`CRM_REPLY_MOVES_DEAL`, on by default) turns this off; the Parameters tab has it as a switch.
- The explanation at the top of the list of automated outreach now describes them as a process for prospecting at scale that stops at the prospect's reply and hands the deal over to the reply stage.
- The MCP tool `create_or_update_crm_stage` takes and returns `is_reply`.
- Database schema version 3: the column `is_reply` of `llx_crm_stage`, and the stage "Replied to outreach" added to **every existing pipeline that has an open stage and no reply stage** (right after its first open stage), when the module is disabled and enabled again. A new installation seeds it. Nothing else of an existing pipeline is changed, and no deal is moved by the update.

## 1.0.0

First version.

### Sales pipeline

- Deals (opportunities) with several **pipelines**, each with its own stages, probabilities, colours, rotting delays and forecast categories. A pipeline can be created (empty or copying the stages of another one), renamed and deactivated.
- Kanban board with drag and drop, deal and activity lists with filters, sortable columns and paging, and a deal card in the order a salesperson works: details, next step, qualification, stage, proposals, history.
- A short editable **qualification** checklist, **loss reasons** (required to close a deal as lost) and lead **sources**. Stages, qualification items, loss reasons and sources are edited in place in the Setup screen and can be turned off: a turned-off entry is no longer offered but stays on the deals that use it, and an entry that is in use (or a stage that deals have passed through) cannot be deleted, only turned off. Deleting asks for a confirmation. The entries seeded at the first enable are shown in the language of the user.
- **Next steps** are Dolibarr agenda events linked to the deal, and a planned one can be changed (date, kind, title) or deleted from the deal card and by MCP (`update_crm_activity`, `delete_crm_activity`); a done step is history and stays; completing, changing or deleting a step is refused when the deal is not one the user may see; a deal cannot change stage between open stages without a planned next step (setting `CRM_REQUIRE_NEXT_STEP`).
- Proposals of the Proposals module can be linked to a deal.
- Home page work lists (no next step, overdue, past expected close, rotting) and forecast by month and category, a home box, and an entry "CRM opportunities" in the general search box of Dolibarr.
- **Reports**: forecast, cohort conversion from the stage history, win rate, cycle time, loss reasons, by owner, by source.
- A prospect can be created from the deal form (button "New prospect"); the account list is searchable.

### Customer follow-up

- **Automated outreach** (menu CRM → Automated outreach): plans of e-mail, call and task steps, each with a delay counted from the enrolment, enrolled on deals chosen in a searchable list, run by an hourly scheduled job. An outreach and its steps can be created and changed (deleting a step asks for a confirmation and says how many running enrolments wait for it); stop rules (contact replied, deal won, deal lost); journal of every attempt that keeps the e-mail as it was made (placeholders replaced, sender, format), also when it was only simulated or left as a draft, with an icon to open it; the deal card lists the e-mails the outreach made for it, with the outreach, the step and the day of the outreach that caused each one.
- Each e-mail step is a **draft** for the salesperson (default) or is **sent automatically**, per step or by the global setting `CRM_SEQUENCE_EMAIL_MODE`; dry-run for automatic sends and for owner reminders; daily reminder e-mail to the owner of the deals.
- **E-mail editor** (`email_compose.php`): review a planned e-mail, tick "Send me a hidden copy (Bcc)" (on by default) to receive a copy of what is sent, choose the sender address (the default of the CRM, the user's own e-mail, or the mailbox of the lead e-mail collector, remembered per user) and see it before sending, send it from Dolibarr, open it in the mail client, register it as sent, or delete the step. Plain text or formatted (HTML) with an automatic plain-text version. The text of an outreach step has the same two formats.
- **E-mail history**: the e-mails sent through the module are kept on the deal, and the answers are read from a dedicated mailbox by Dolibarr's e-mail collector (operation "CRM: record the e-mail on its deal"). A message is tied to its deal by tracking id, by thread, or by the deal reference in square brackets in the subject. A reply stops the running outreach of the deal.
- **AI assistance**, off by default (`CRM_AI_EMAIL_ASSIST`) and using the AI module of Dolibarr: a reply proposed from the exchange so far, with an editable prompt, and a button that writes or improves the text of an outreach step. The privacy redaction of the AI module is honoured. The calls to the AI service wait up to `CRM_AI_TIMEOUT` seconds (default 120) instead of Dolibarr's 30, for the CRM calls only, and a failure without any HTTP answer says how long it took and whether it timed out or the connection failed.

### Administration

- Setup screen: parameters (on/off switches saved at once), pipelines and stages, qualification, loss reasons, sources; rights, scheduled jobs and hooks registered at the enable; seeded data inserted once.
- **MCP tools** (50, named like `list_crm_opportunities` or `update_crm_activity`) for an AI assistant, within the rights of the user; they check their own rights, as Dolibarr 24.0.0 does not check the rights a tool declares.
- Explanations of more than 12 words are hidden until the "?" button of the page is clicked; row actions are icons; buttons use the same markup as Dolibarr's own, so the theme styles them alike.
- English and French, and a machine translation (not reviewed by a native speaker) for the other languages Dolibarr supplies. Errors shown by the module are translated.
- Documentation: administrator guide (`doc/ADMIN_GUIDE.md`), user guide (`doc/USER_GUIDE.md`), design record (`doc/STRUCTURE_ANALYSIS.md`), `README.md`, `AGENTS.md`.

### Compatibility

- Dolibarr 24.0.0 and 25.0.0 alpha (tested); 21 to 23 are accepted by the descriptor and have not been tested. PHP 8.1 or later.
- The MCP server and the AI features need the AI module (Dolibarr 24 or later). The MCP tools are named verb first, as the core tools are, so that the tool access screen of Dolibarr classes a tool that changes data as "Modify" and its "View only" preset leaves it out. They are registered the way the 24.0.0 loader requires (checked against a 24.0.0 core and the 25 alpha one).
- Schema: the tables `llx_crm_*` are created at the first enable. The module keeps its own schema version (`CRM_SCHEMA_VERSION`, shown in CRM → Setup → About) and runs the missing numbered steps when it is disabled and enabled again, with a notice to administrators when an update is waiting for that. See `INSTALL.md` and `UPDATE.md`.
- Documentation: `INSTALL.md` (installation) and `UPDATE.md` (update procedure and how to release a version).
