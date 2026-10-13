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

Technical name `crm`: rights, `isModEnabled('crm')`, tables `llx_crm_*`, constants `CRM_*`, MCP tools `crm_*`. Dolibarr already uses `crm` as a module family (the category commercial modules are filed under). No core module has this name, and no core table or constant uses those prefixes.

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

Agenda events use the existing types `AC_TEL`, `AC_RDV`, and `AC_OTH`. The module does not insert rows into `llx_c_actioncomm`. A logged e-mail uses `AC_EMAIL` only when that type is already active, otherwise `AC_OTH`. Creating an event goes through `ActionComm`. The third party and the contact are set on the event, so it shows on the account and in the calendar, including CalDAV.

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
| Customer drip sequence as a primary feature, dry-run on | Kept, but off until an admin turns it on. The default mail is an internal reminder to the owner. |
| Custom application shell and its own sidebar | Dolibarr menus, standard cards and lists. One kanban page. |
| Company e-mail as the sequence recipient | The deal's primary contact. No contact address means no send. |

## Pages

Top menu **CRM**. Left menu entries come from the module descriptor.

| Page | Role |
| --- | --- |
| `index.php` | Work to do: no next step, overdue next step, expected close in the past, rotting. Then forecast for the coming months. |
| `pipeline.php` | Kanban for one pipeline, filtered by owner. Drag and drop moves the stage. |
| `opportunity_list.php` | Ref, title, account, contact, stage, amount, expected close, owner, next step date, qualification gaps |
| `opportunity_card.php` | Deal, linked proposals, qualification, next step, timeline of agenda events, win / loss |
| `activity_list.php` | A filtered view of `llx_actioncomm` for CRM deals. Not a second store. |
| `sequence_list.php`, `sequence_card.php` | Customer cadences, enrolments, journal |
| `report.php` | Forecast, cohort conversion, win rate, loss reasons, cycle time, by owner, by source |
| `admin/setup.php` | Parameters, pipelines and stages, qualification items, loss reasons, sources |

The home box lists the current user's overdue steps and deals with no next step.

## Pipeline rules

Several pipelines (new business, renewal, partners), each with its own stages.

A stage has label, position, colour, probability, rotting delay, forecast category (`pipeline`, `upside`, `commit`), and a won or lost flag. Won is probability 100 and category closed. Lost is excluded from the forecast.

Open deal hygiene, in order:

1. Every open deal should have a future agenda event. `CRM_REQUIRE_NEXT_STEP` defaults to on: a move between open stages is refused while no future event exists. Won and lost do not need a next step.
2. A deal is rotting when it is open and its latest done event (or its creation, if nothing was done) is older than the stage delay. Rotting is a warning, not a stage change.
3. An open deal whose expected close is in the past is a forecast error and is listed as such. The date is not rewritten by the module.

Closing lost requires a loss reason. Closing won asks for one and allows empty. Both write `date_close` and a stage-history row.

`llx_crm_stage_history` stores deal, from-stage, to-stage, user, date. Cohort conversion is computed from that table: of deals created in the chosen period, how many later entered each stage. The kanban column counts stay on the board, where they belong, and are not presented as a funnel.

Win rate is won / (won + lost) by close date in the period. Cycle time is the mean of (close date − creation) on won deals only.

## Qualification

`llx_crm_qualification` is a short checklist the admin can edit. Seed, in order:

| Code | Label | Points |
| --- | --- | --- |
| `NEED` | Need confirmed | 25 |
| `BUDGET` | Budget confirmed | 25 |
| `DECISION` | Decision maker identified | 25 |
| `TIMELINE` | Timeline confirmed | 25 |

Answers live in `llx_crm_opportunity_qualification` (yes/no, date, user). The card lists what is still missing. The numeric score is the sum of the points of the yes answers, capped at 100. There is no generic operator engine and no grade badge.

## Customer sequences and owner reminders

Two jobs, registered in `$this->cronjobs`, run by Dolibarr cron. Each returns immediately when the module is disabled. Suggested frequency: hourly. Cap: `CRM_MAX_EMAILS_PER_RUN` (50).

**Owner reminders** (`CrmCron::runOwnerReminders`). On by default.

One message per owner per day. It lists overdue next steps, deals with no next step, and open deals whose expected close is in the past. `CRM_REMINDER_DRY_RUN` defaults to on, so the first runs only fill `llx_crm_reminder_log` (owner, date, status, error). A row for that owner and that day blocks a second message.

**Customer sequences** (`CrmCron::runSequences`). Off by default (`CRM_SEQUENCES_ON` = 0).

A sequence is an explicit cadence on a deal, not a blast to every prospect. Steps are `email`, `call`, or `task`, delayed D+n from enrolment.

- E-mail goes to `fk_socpeople` on the deal. No address: log `error` and do not spin. The company address is not a fallback.
- Placeholders `__DEAL__`, `__REF__`, `__AMOUNT__`, `__CONTACT__`, `__CUSTOMER__`. An unknown token is left as written.
- `call` and `task` create an agenda event for the owner. They do not send mail.
- A real send uses `CMailFile` and then logs an agenda event.
- Stop when the deal is won or lost, when the enrolment is stopped, when someone marks "prospect replied" (card or MCP), or when the e-mail collector records an answer from the customer on the deal (see "E-mail history").
- `CRM_DRY_RUN` defaults to on even after sequences are enabled. Simulated rows advance the enrolment so the cadence can be checked. The sequence screen shows that banner.
- Sender is `CRM_MAIL_FROM`, or the company sender when that constant is empty.

## E-mail history

The full exchange with a customer is kept as agenda events on the deal, not in a table of the module. A message is one done event (`percent` 100, so it is never counted as a next step) with `elementtype = 'opportunity@crm'`, the plain-text body in the note, and Dolibarr's own e-mail columns (`email_msgid`, from, to, cc, subject). Code `AC_EMAIL` for a message we sent, `AC_EMAIL_IN` for one we received; the event type is the active one chosen by `CrmActivity::typeCode()`.

Sending. Every customer e-mail sent for a deal goes through `crmSendMail($to, $subject, $body, $dealId)`. It gives the message the tracking id `crm<dealId>`, so the Message-ID is `<unique>.<transport>-dolibarr-crm<dealId>@<host>`, and copies it to `CRM_ARCHIVE_MAILBOX` as Bcc (default) or Cc (`CRM_ARCHIVE_COPY`). The event is written at send time with that Message-ID. Owner reminders carry no deal, no tracking id and no copy.

Reading. The module has no IMAP code. The Dolibarr **e-mail collector** (core module) reads the archive mailbox. The module adds an operation to the collector, "CRM: record the e-mail on its deal" (`hookcrmrecordemail`), through the hooks `addMoreActionsEmailCollector` (context `emailcollectorcard`) and `doCollectImapOneCollector` (context `emailcollector`), both in `ActionsCrm`. The operation goes to `CrmMailArchive::record()`. A message is kept only when:

1. its Message-ID, In-Reply-To or References carries `dolibarr-crm<dealId>@` (the copy of what the module sent, or an answer that threads on it); or
2. one of those ids is the Message-ID of a message already recorded on a deal. This catches a salesperson who answers from their own mail client, quoting the exchange, with the archive mailbox in copy.

Anything else is ignored without error, so the mailbox is not a general inbox and the other operations of the collector are not disturbed. A message already recorded (same Message-ID) is skipped, which is how the Bcc copy of a module e-mail does not create a second event. The direction is "sent" when the sender is the module sender, an address in `MAIL_FROM_EMAILS_TO_CONSIDER_SENDING`, or a Dolibarr user's e-mail, otherwise "received". A received message stops the running enrolments of the deal whose sequence has `stop_on_reply`.

Limits. A message composed from scratch, with no thread to a recorded one, cannot be tied to a deal. A Bcc copy is not in the customer's "reply all", so their answer reaches the mailbox only if a person copies or forwards it; use Cc if the customer's replies should be seen directly. The note holds the plain-text body: attachments are not stored. Events follow the agenda's visibility rules, not the CRM's.

## Data the module owns

One file, `sql/llx_crm.sql`. Indexes are inside each `CREATE TABLE`. There is no `IF NOT EXISTS` and no separate `.key.sql`. No `ALTER` on a core table and no extrafield. Every table has `entity` except `llx_crm_sequence_step`, which belongs to its sequence.

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
| `llx_crm_sequence_step` | Position, action, delay days, subject, body |
| `llx_crm_sequence_enrol` | Deal, current step, next run, status, stop reason |
| `llx_crm_sequence_log` | Recipient, subject, date, `sent` / `simulated` / `error` |
| `llx_crm_reminder_log` | Owner reminder journal |

Opportunity columns: `ref` (`OPP` + year + sequence), `label`, `fk_soc`, `fk_socpeople`, `fk_pipeline`, `fk_stage`, `amount`, `date_close_expected`, `fk_source`, `fk_user_resp`, `description`, `status` (`open` / `won` / `lost`), `date_close`, `fk_loss_reason`, `loss_note`, `datec`, `tms`, `fk_user_creat`. Probability is not a column.

Seed, only when that entity has no pipeline yet: one pipeline, six stages (New 10% pipeline, Qualified 25% pipeline, Proposal sent 50% upside, Negotiation 75% commit, Won 100% won, Lost 0% lost), six sources (website, referral, inbound call, event, partner, prospecting), the four qualification items, and loss reasons (price, no budget, competitor, timing, no decision, no answer). Re-enabling does not insert them again.

Constants: `CRM_ROTTING_DAYS` (15, default for a new stage), `CRM_FORECAST_MONTHS` (3), `CRM_MAX_EMAILS_PER_RUN` (50), `CRM_MAIL_FROM`, `CRM_SEQUENCES_ON` (0), `CRM_DRY_RUN` (1), `CRM_REMINDERS_ON` (1), `CRM_REMINDER_DRY_RUN` (1), `CRM_REQUIRE_NEXT_STEP` (1), `CRM_ARCHIVE_MAILBOX` (empty: no copy), `CRM_ARCHIVE_COPY` (`bcc` or `cc`), `MAIN_MODULE_CRM_ICON` (read by the theme).

## MCP

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
| `crm_list_pipelines`, `crm_list_stages`, `crm_list_sources`, `crm_list_loss_reasons`, `crm_list_qualifications` | no |
| `crm_upsert_stage`, `crm_delete_stage`, `crm_upsert_source`, `crm_upsert_loss_reason`, `crm_upsert_qualification` | yes |
| `crm_list_opportunities`, `crm_get_opportunity`, `crm_list_stage_history` | no |
| `crm_create_opportunity`, `crm_update_opportunity`, `crm_move_opportunity` | yes |
| `crm_mark_won`, `crm_mark_lost`, `crm_delete_opportunity` | yes |
| `crm_link_proposal`, `crm_unlink_proposal`, `crm_set_qualification` | yes |
| `crm_list_activities` | no |
| `crm_create_activity`, `crm_complete_activity` | yes, agenda events |
| `crm_list_sequences`, `crm_get_sequence` | no |
| `crm_create_sequence`, `crm_add_sequence_step`, `crm_delete_sequence_step` | yes |
| `crm_enrol_sequence`, `crm_stop_sequence`, `crm_mark_replied` | yes |
| `crm_get_dashboard`, `crm_get_forecast`, `crm_get_conversion`, `crm_get_loss_reasons` | no |
| `crm_get_win_rate`, `crm_get_cycle_time`, `crm_get_by_owner`, `crm_get_by_source` | no |
| `crm_get_settings` | no |
| `crm_set_settings`, `crm_run_sequences`, `crm_run_owner_reminders` | yes |

There is no core tool to search users. Owner and assignee arguments are numeric user ids. There is no `crm_list_users`.

## Permissions

`$user->hasRight('crm', ...)`.

| Permission | Effect |
| --- | --- |
| `read` | Own deals, own pipeline, own activities |
| `write` | Create and edit deals, move stages, qualify, link proposals |
| `delete` | Delete deals |
| `readall` | Every owner's deals, in the UI and in MCP |
| `activity` | Create and complete agenda events for a deal |
| `sequence` | Manage sequences and enrolments |
| `report` | Reports and the report tools |
| `setup` | Constants, stages, qualification, dictionaries |

## Install and removal

`init()` loads `sql/`, seeds only an empty entity, and registers menus, rights, the home box, and both cron jobs.

`remove()` unregisters menus, rights, the box, and the cron jobs. It does not `DROP` `llx_crm_*`, does not delete `llx_actioncomm` rows, and does not touch `llx_societe`, `llx_socpeople`, `llx_propal`, or `llx_user`. Re-enable finds the deals again.

No purge button in v1. A later action can drop only `llx_crm_*` and `CRM_*` constants after a typed confirmation. Agenda events stay even then, unless that later action says otherwise.

## File tree

```text
/var/www/dolibarr-crm/                 # symlinked to htdocs/custom/crm
├── core/
│   ├── modules/modCrm.class.php       # modCrm, id 104801
│   └── boxes/crmwidget.php
├── class/
│   ├── actions_crm.class.php          # addMcpTools, e-mail collector operation
│   ├── crmmailarchive.class.php       # e-mail to deal matching and recording
│   ├── opportunity.class.php
│   ├── pipeline.class.php
│   ├── stage.class.php
│   ├── crmactivity.class.php
│   ├── qualification.class.php
│   ├── sequence.class.php
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
├── README.md
└── doc/STRUCTURE_ANALYSIS.md
```

Module id: 104801. Rights ids 10480101–10480108.

PHP 8.1+, `declare(strict_types=1)`, tabs. Pages keep actions and view apart. Stage moves, closing, qualification, sending, and reminders live in the classes, so the pages, the cron, and the MCP tools call the same methods.

Kanban drag and drop posts to `pipeline.php` with a CSRF token. No third-party script.

## Out of scope

- Core patches, extrafields, and new agenda event types.
- A mailbox connector of our own. Reading the archive mailbox is done by the Dolibarr e-mail collector (see "E-mail history"). Mail that is not sent by the module and does not quote an exchange already recorded is not archived.
- Languages beyond English and French.
- A REST API beside MCP.
- Importing existing proposals into deals.
- A generic scoring-rule engine.
- Multi-currency amounts. The amount is the company currency, same as a proposal with multicurrency left off. Revisit if this instance relies on multicurrency.

## Decisions kept

1. Customer sequences ship disabled, with dry-run on. Owner reminders ship enabled, also dry-run until that is turned off.
2. Calls and meetings are agenda events and are kept after the module is disabled.
3. An open deal cannot change stage until it has a future next step (`CRM_REQUIRE_NEXT_STEP`, default on).
4. Owners in MCP are numeric user ids. There is no `crm_list_users`.
5. The module lives in `/var/www/dolibarr-crm` and is reached by the `htdocs/custom/crm` symlink. The empty alternate-root fallback already scans `htdocs/custom`, so the two `conf.php` lines stay commented.
