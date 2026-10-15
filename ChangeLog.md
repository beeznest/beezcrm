# CHANGELOG MODULE CRM FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

The version of the module is the `$this->version` property of `core/modules/modCrm.class.php`. This file is shown by Dolibarr on the help page of the module (Home → Setup → Modules, help icon of the CRM line). Newest version first. A change a user or an administrator can notice goes here, in the same change.

## 1.1.0

### Prospects who reply

- A new stage, **Replied to outreach** (shown in French as "Répondu à approche"), between New and Qualified in the standard pipeline (15%, forecast category `pipeline`). It is the **reply stage** of its pipeline, marked by the new checkbox "Reply stage" of the stage form (one per pipeline; it must be an open stage; copying a pipeline copies the mark).
- When the answer of a prospect is recorded on an open deal, the deal **moves by itself to the reply stage** of its pipeline: when the e-mail collector records an incoming message on the deal, and when someone presses "Mark replied" on an enrolment of an automated sequence (screen or MCP tool `update_crm_opportunity_replied`, which now also moves the deal). The deal moves only when it is still before that stage: one that is already there or beyond it, or won or lost, stays. The move is written in the stage history, and the rule "a next step is required to change stage" does not apply to it. The salesperson then qualifies the deal.
- The setting **"Move a deal to the reply stage when the prospect replies"** (`CRM_REPLY_MOVES_DEAL`, on by default) turns this off; the Parameters tab has it as a switch.
- The explanation at the top of the list of automated sequences now describes them as a process for prospecting at scale that stops at the prospect's reply and hands the deal over to the reply stage.
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

- **Automated sequences** (menu CRM → Automated sequences): plans of e-mail, call and task steps, each with a delay counted from the enrolment, enrolled on deals chosen in a searchable list, run by an hourly scheduled job. A sequence and its steps can be created and changed (deleting a step asks for a confirmation and says how many running enrolments wait for it); stop rules (contact replied, deal won, deal lost); journal of every attempt that keeps the e-mail as it was made (placeholders replaced, sender, format), also when it was only simulated or left as a draft, with an icon to open it; the deal card lists the e-mails the sequences made for it, with the sequence, the step and the day of the sequence that caused each one.
- Each e-mail step is a **draft** for the salesperson (default) or is **sent automatically**, per step or by the global setting `CRM_SEQUENCE_EMAIL_MODE`; dry-run for automatic sends and for owner reminders; daily reminder e-mail to the owner of the deals.
- **E-mail editor** (`email_compose.php`): review a planned e-mail, tick "Send me a hidden copy (Bcc)" (on by default) to receive a copy of what is sent, choose the sender address (the default of the CRM, the user's own e-mail, or the mailbox of the lead e-mail collector, remembered per user) and see it before sending, send it from Dolibarr, open it in the mail client, register it as sent, or delete the step. Plain text or formatted (HTML) with an automatic plain-text version. The text of a sequence step has the same two formats.
- **E-mail history**: the e-mails sent through the module are kept on the deal, and the answers are read from a dedicated mailbox by Dolibarr's e-mail collector (operation "CRM: record the e-mail on its deal"). A message is tied to its deal by tracking id, by thread, or by the deal reference in square brackets in the subject. A reply stops the running sequences of the deal.
- **AI assistance**, off by default (`CRM_AI_EMAIL_ASSIST`) and using the AI module of Dolibarr: a reply proposed from the exchange so far, with an editable prompt, and a button that writes or improves the text of a sequence step. The privacy redaction of the AI module is honoured. The calls to the AI service wait up to `CRM_AI_TIMEOUT` seconds (default 120) instead of Dolibarr's 30, for the CRM calls only, and a failure without any HTTP answer says how long it took and whether it timed out or the connection failed.

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
