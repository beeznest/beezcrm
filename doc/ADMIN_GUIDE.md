# CRM administrator guide

For the person who installs the CRM module, configures it, and keeps it running. Salespeople use [the user guide](USER_GUIDE.md). The design reasons behind the rules are in [STRUCTURE_ANALYSIS.md](STRUCTURE_ANALYSIS.md).

Contents: [Requirements](#requirements) · [Dolibarr settings the module relies on](#dolibarr-settings-the-module-relies-on) · [Install](#install) · [Rights](#rights) · [Module setup, page by page](#module-setup-page-by-page) · [Scheduled jobs](#scheduled-jobs) · [Sending e-mail](#sending-e-mail) · [Sequences](#sequences) · [E-mail history](#e-mail-history) · [MCP](#mcp) · [Maintenance](#maintenance) · [Settings reference](#settings-reference) · [Troubleshooting](#troubleshooting)

## Requirements

- Dolibarr 21 or later, PHP 8.1 or later.
- The Dolibarr modules **Third parties** and **Agenda** must be enabled: the CRM module depends on them, and Dolibarr will not enable it otherwise.
- Optional, each for one feature: **Proposals** (to link proposals to a deal), **AI** (MCP tools), **E-mail collector** (e-mail history), working **e-mail sending** (sequences, reminders).

## Dolibarr settings the module relies on

The module works only if these global Dolibarr settings are in place. Go through the list once, before you configure the module itself. "Needed for" says which feature breaks without it.

**Modules** (Home → Setup → Modules)

| Module | Needed for |
| --- | --- |
| **Third parties** | Required. Accounts, contacts, the account list in the deal form, the "New prospect" button |
| **Agenda** | Required. Next steps, calls, meetings and e-mail history are agenda events. Without it the module refuses to enable |
| **Scheduled jobs** (module `cron`) | The sequence job, the owner reminders and the e-mail collector. Without it Dolibarr never runs a scheduled job, whatever the system cron does |
| **Proposals** | Linking a proposal to a deal. Without it the Proposals section of the deal is simply absent |
| **E-mail collector** | Reading the archive mailbox (e-mail history). Not needed if you do not archive e-mails |
| **AI** | The MCP tools. Not needed for the screens |

**E-mail sending** (Home → Setup → Emails). Needed for reminders and for any real send from a sequence or from the "Send from here" button.

- Send method, SMTP server, port, login and password, encryption: set them for your provider, then use the test button of that page to send yourself a message. If this test fails, every send from the module will fail.
- **Sender e-mail** (`MAIN_MAIL_EMAIL_FROM`): used as the module sender when `CRM_MAIL_FROM` is empty. If that is empty too, the company e-mail (Home → Setup → Company) is used. One of the three must be set, or nothing can be sent.
- **Disable all e-mails** (`MAIN_DISABLE_ALL_MAILS`) must be **off**, otherwise every send fails with "Feature is disabled by option MAIN_DISABLE_ALL_MAILS".
- **Force the recipient** (`MAIN_MAIL_FORCE_SENDTO`), when set, redirects every e-mail to that address. Handy on a test instance, to be removed before going live, or customers receive nothing.
- **Automatic copy** (`MAIN_MAIL_AUTOCOPY_TO`), when set, adds a hidden copy to every e-mail Dolibarr sends, including those of the module. This is independent of the CRM archive mailbox.

**Users** (Users → each user)

- Each salesperson needs an **e-mail address** on their user card: it is where the owner reminders go, and it is how the e-mail history recognises a message "sent by us" (the sender's address belongs to a Dolibarr user).
- Each user's **language** decides which translation of the module they see. English and French are supplied; any other language shows English.
- To read an e-mail event of a deal in the agenda (the link on the deal card), a user also needs the Agenda right to see events. The CRM screens themselves do not need it.

**Third parties**

- A user needs the third-party rights to **read** (the account list, the search) and to **create** (the "New prospect" button).
- Home → Setup → Third parties: keep **prospects** enabled; the "New prospect" button opens the third-party form already set as a prospect. If your instance has many third parties, set the option "Use a search to select third parties" (`COMPANY_USE_SEARCH_TO_SELECT`): the account list of the deal form then searches as you type instead of loading everything. Both modes work.

**Scheduled jobs** (Home → Setup → Scheduled jobs). A security key (`CRON_KEY`) must exist: the script refuses to run with an empty or wrong key. See [Scheduled jobs](#scheduled-jobs).

**Server**

- PHP 8.1 or later. For the e-mail history, the PHP **imap** extension (`php-imap`) on the server, which the e-mail collector uses to read the mailbox; as an alternative, Dolibarr's constant `MAIN_IMAP_USE_PHPIMAP` makes the collector use a library instead, which is also the way to read a mailbox that needs OAuth.
- A **system cron entry** that calls Dolibarr's cron script, see [Scheduled jobs](#scheduled-jobs).

**MCP** (only if you use it). The AI module, the constant `MAIN_FEATURES_LEVEL` at 1 or more to see the tabs, and the MCP settings: see [MCP](#mcp). No AI provider key is needed for the MCP server itself; that is only for Dolibarr's own assistant.

**Optional**

- `MAIL_FROM_EMAILS_TO_CONSIDER_SENDING` (Home → Setup → Other setup): comma-separated addresses, such as a shared sales mailbox, that must count as "sent by us" in the e-mail history even though no Dolibarr user has that address.
- `SEARCHFORM_POSITION_CRM`: position of the "CRM opportunities" entry in the general search box (default 42). `DEFAULT_SEARCH_INTO_MODULE=crmopportunity` puts that entry first.
- Types of agenda events (Home → Setup → Dictionaries): see [Maintenance](#maintenance).

## Install

The module root is the directory that contains `core/modules/modCrm.class.php`. Dolibarr finds external modules in `htdocs/custom`, so install it there, preferably as a symbolic link to wherever the module lives:

```text
ln -sfn /path/to/crm /path/to/dolibarr/htdocs/custom/crm
```

The link name must be `crm`. A packaged zip can instead be installed from Home → Setup → Modules → "Deploy an external module", which unpacks it into the same place.

`htdocs/custom` is scanned when `$dolibarr_main_document_root_alt` in `conf.php` is empty, or when it lists that directory (`$dolibarr_main_url_root_alt='/custom'` and the matching path). Nothing else in `conf.php` is needed.

Then, as an administrator:

1. Home → Setup → Modules, family **CRM**: enable the module. A top menu **CRM** appears.
2. [Grant rights](#rights) to your users.
3. Open CRM → Setup and read [Setup screen](#setup-screen).

**What the first enable does.**

- Creates the tables `llx_crm_*`.
- Creates the constants `CRM_*` with their defaults (see [Settings reference](#settings-reference)).
- Seeds, only when the entity has no pipeline: one **Commercial pipeline** with six stages (New 10%, Qualified 25%, Proposal sent 50%, Negotiation 75%, Won, Lost), seven lead sources (website, referral, inbound call, event, partner, prospecting, inbound e-mail), four qualification items (need, budget, decision maker, timeline) and six loss reasons (price, no budget, competitor, timing, no decision, no answer). Later enables do not seed again and do not reset your constants.
- Registers the menus, the rights, a home box ("CRM follow-up") and two scheduled jobs.
- Registers hooks (see [Maintenance](#maintenance)).

The seeded entries are stored in English and shown in the language of each user (English and French are supplied). An entry you rename, or create, is shown exactly as you typed it.

## Rights

Users → Permissions, family CRM. A user can see and do only what the rights allow, in the screens and in the MCP tools.

| Right | Allows |
| --- | --- |
| Read own deals | See the deals they own, the pipeline, the activities. The minimum to use the module |
| Read every owner | See every owner's deals. Without it a user only ever sees their own |
| Create and update deals | Create and edit deals, move stages, qualify, link proposals |
| Delete deals | Delete deals (their agenda events are kept) |
| Create and complete next steps | Add, complete, write and send e-mail steps on a deal |
| Manage customer sequences | Create sequences and steps, enrol deals, stop, mark replied |
| Read CRM reports | The Reports page |
| Configure CRM | The Setup screen, and the MCP tools that change settings |

Typical profiles: a **salesperson** gets Read own, Create and update, Create and complete next steps. A **sales manager** adds Read every owner, Read CRM reports, and Manage sequences. Only you (or a delegated administrator) need Configure CRM and Delete.

Two more rights matter outside CRM: creating a prospect from the deal form needs the Dolibarr right to create third parties, and the general search box and the account list only show third parties the user may read.

## Module setup, page by page

Everything the module lets you configure is under **CRM → Setup** (needs "Configure CRM"), plus the **Sequences** screens. The gear icon of the module in Home → Setup → Modules opens the same Setup screen.

### CRM → Setup → Parameters

One text field per setting; press **Save** once for the whole tab. For an on/off setting, type `1` for on and `0` for off. A setting left empty counts as off (or, for the mail sender, as "use the Dolibarr sender").

| Field | Constant | What it does |
| --- | --- | --- |
| Default rotting days for a new stage | `CRM_ROTTING_DAYS` (15) | The value offered in the "rotting days" field when you add a stage (Stages tab). A deal is **rotting** when it is open and its latest completed activity (or its creation, when none) is older than the stage's rotting days. It is a warning on the board and on Home, never an automatic move. A stage with 0 never rots |
| Forecast window (months) | `CRM_FORECAST_MONTHS` (3) | How many months ahead the forecast on Home and in Reports covers |
| Maximum e-mails per cron run | `CRM_MAX_EMAILS_PER_RUN` (50) | Stops one run of the sequence job after this many sent or simulated e-mails; the rest wait for the next run. Drafts do not count |
| Sender address | `CRM_MAIL_FROM` (empty) | Sender of the customer e-mails and of the reminders. Empty: the Dolibarr sender (`MAIN_MAIL_EMAIL_FROM`), then the company e-mail |
| Customer sequences enabled | `CRM_SEQUENCES_ON` (0) | Master switch of the sequence job. While 0 no step is run, draft or send |
| Sequence e-mail steps | `CRM_SEQUENCE_EMAIL_MODE` (`draft`) | A drop-down: **Draft for the salesperson** (default) or **Send automatically**. See [Sequences](#sequences) |
| Sequence dry-run | `CRM_DRY_RUN` (1) | In send mode, journal only: nothing is sent and nothing is copied to the archive. Not used in draft mode |
| Owner reminders enabled | `CRM_REMINDERS_ON` (1) | The daily digest e-mail to each deal owner: overdue next steps, deals with no next step, deals past their expected close. At most one per owner per day |
| Reminder dry-run | `CRM_REMINDER_DRY_RUN` (1) | Journal only for the reminders. Switch to 0 when the journal looks right |
| Require a future next step before an open stage change | `CRM_REQUIRE_NEXT_STEP` (1) | While 1, a deal cannot be moved from one open stage to another unless it has an unfinished agenda event dated today or later. Moving to Won or Lost never needs it. Set 0 to allow free moves |
| Archive mailbox address | `CRM_ARCHIVE_MAILBOX` (empty) | The dedicated address copied on every customer e-mail sent for a deal. Empty: no copy |
| Copy mode for the archive mailbox | `CRM_ARCHIVE_COPY` (`bcc`) | A drop-down: **Hidden copy (Bcc)** or **Visible copy (Cc)** |

Under the table a note reminds you that sequences stay off until enabled and that dry-run is on for sequences and reminders, and a longer help text explains the e-mail history setup.

### CRM → Setup → Stages

Pipelines and their stages. From top to bottom:

1. **Pipeline list and Filter button.** Picks the pipeline whose stages are shown and edited below. It lists inactive pipelines too.
2. **Stage table.** For the selected pipeline, in board order: label, position, probability, forecast category, and a **Delete stage** link. A stage in which a deal sits cannot be deleted (the screen says so).
3. **Selected pipeline** (a warning appears if the pipeline has no open stage). Shows the fixed reference, and lets you change the **label** and the **Active** checkbox, then **Save**. An inactive pipeline is no longer offered for new deals or on the board; its deals stay in the lists and reports. The last active pipeline cannot be deactivated.
4. **Add** (a stage to the selected pipeline). The fields, in order:
   - *Label*: the name shown on the board.
   - *Position*: a number; stages are shown in ascending order. Leave gaps (0, 10, 20…) to insert later.
   - *Colour*: `#rrggbb`. The top border of the column and the stage badge. An invalid value falls back to grey.
   - *Probability* (%): the chance of winning at this stage, 0 to 100. A deal has no percentage of its own; the weighted forecast is amount × stage probability.
   - *Rotting days*: see the Parameters tab. 0 disables the warning.
   - *Forecast category*: `pipeline`, `upside` or `commit` for open stages (the forecast groups by it: commit is what you would bet on); `closed` for a won stage; `lost` for a lost stage. Won and lost deals are never forecast.
   - *Won stage* / *Lost stage* checkboxes. A deal moved into a won stage becomes **won**: it gets its closing date and leaves the board and the forecast. A deal moved into a lost stage becomes **lost** and **requires a loss reason**. Tick neither for an open stage.
5. **New pipeline.** Reference (letters, digits, underscore, dash; stored in capitals; unique; never changes), label, and **Copy the stages of**: pick a pipeline to start from its stages, probabilities, colours and flags, or "Nothing (empty pipeline)". Create, and the page opens the new pipeline.

Rules when you design stages: a pipeline needs at least one **open**, one **won** and one **lost** stage; a new deal starts in the first open stage by position, and without one nobody can create a deal in that pipeline. Typical shape: New → Qualified → Proposal sent → Negotiation → Won / Lost, with probabilities rising from 10% to 75%.

**Limit of the screen.** Stages are added and deleted here, not edited in place. To change the probability, the label or the colour of an existing stage, create the corrected stage and delete the old one (only possible while no deal is in it), or use the MCP tool `crm_upsert_stage`.

### CRM → Setup → Qualification

The checklist a salesperson ticks on each deal. A table lists the active items (code, label, points) and a form adds one: **code** (unique, letters and digits, for example `NEED`), **label** (shown to users), **points** (default 25) and **position**. The score of a deal is the sum of the points of the ticked items, capped at 100; the card lists the unticked items as "missing". Keep the total around 100 so the score reads as a percentage. The seeded items are Need, Budget, Decision maker, Timeline, 25 points each. There is no scoring-rule engine and no grade.

### CRM → Setup → Loss reasons

The reasons offered when a deal is closed as lost, which is **required** to close it. A table lists them (code, label) and a form adds one (code, label, position). The Reports page counts deals and amounts per reason, so keep the list short and distinct. Seeded: price, no budget, competitor, timing, no decision, no answer.

### CRM → Setup → Sources

Where a lead came from, offered on the deal and used by the "by source" report. Same table and form as the loss reasons. Seeded: website, referral, inbound call, event, partner, prospecting, inbound e-mail.

For these three dictionaries the screen **adds** entries; it does not edit, reorder or deactivate them. To rename, reorder or deactivate an entry (a deactivated entry is no longer offered but keeps its history), use the MCP tools `crm_upsert_qualification`, `crm_upsert_loss_reason` and `crm_upsert_source`. A code that already exists is refused by the screen.

### CRM → Setup → About

A short note on what the module keeps in Dolibarr and what disabling it leaves behind.

### CRM → Sequences

Not in the Setup screen, but it is configuration, and it needs "Manage customer sequences". The list (reference, label, active, number of steps) has a form to create a sequence: **label** and **Active** checkbox. The reference is generated. Open a sequence to manage its steps, enrolments and journal: see [Sequences](#sequences). A sequence's Active flag is set when you create it; an inactive sequence runs nothing.

### Other places that carry module settings

- **Home box "CRM follow-up"**: enabled on Home by default. It lists the current user's overdue steps and deals without a next step. A user can hide or move it like any Dolibarr box (Home → Setup → Boxes, or the box menu on Home).
- **Rights**: Users → Permissions, see [Rights](#rights).
- **Scheduled jobs**: Home → Setup → Scheduled jobs, see below.

## Scheduled jobs

Dolibarr has **no scheduler of its own**: its scheduled jobs run only when something calls them. Without the entry below, none of the CRM jobs and not the e-mail collector ever run.

0. The Dolibarr module **Scheduled jobs** must be enabled (Home → Setup → Modules).
1. Home → Setup → Scheduled jobs: note the security key (`CRON_KEY`); set one if it is empty, because the script refuses an empty or wrong key. The login you give must be an active Dolibarr user, normally an administrator.
2. Add a system cron entry, as the web user or a user who can write Dolibarr's files:

```text
*/5 * * * * php /path/to/dolibarr/scripts/cron/cron_run_jobs.php <CRON_KEY> <admin login> >> /var/log/dolibarr_cron.log 2>&1
```

The CRM module registers two jobs, enabled, hourly:

| Job | What it does |
| --- | --- |
| Owner reminders (`CrmOwnerReminders`) | At most one digest per owner per day listing overdue next steps, deals with no next step, and deals past their expected close. Controlled by `CRM_REMINDERS_ON` and `CRM_REMINDER_DRY_RUN` |
| Customer sequences (`CrmCustomerSequences`) | Runs the due steps of the enrolled deals. Does nothing while `CRM_SEQUENCES_ON` is off |

In Scheduled jobs you can start any job by hand ("Execute now"), which is the quickest way to test one. The last run date and result are shown there. A job whose last run is empty has never been called: check the cron entry.

## Sending e-mail

Two parts of the module can send mail: the **reminders** (to the deal owner) and the **sequences** (to the deal contact, only in `send` mode, see below). Both use Dolibarr's own mail settings.

1. Home → Setup → Emails: choose the send method, the SMTP server and login, and send a test message from that page. Until this works, no real send can.
2. Set `CRM_MAIL_FROM`, or leave it empty to use the sender configured in Dolibarr.

Customer e-mails go to the **contact** of the deal, never to the company address. A deal with no contact address gets no automatic e-mail.

Dry-run keeps the first runs harmless: with dry-run on, a run only writes the journal (reminders: `llx_crm_reminder_log`; sequences: the sequence's journal) and nothing is sent. Check the journal, then switch dry-run off.

## Sequences

A sequence is an explicit follow-up plan for a **deal**, not a mass mailing. CRM → Sequences (needs "Manage customer sequences").

1. Create the sequence (label, active). Open it.
2. Add **steps** in the order they should run. Each step has a kind (**e-mail**, **call**, **task**), a **delay in days after enrolment** (not after the previous step), a subject and a body. Subject and body accept the placeholders `__DEAL__`, `__REF__`, `__AMOUNT__`, `__CONTACT__`, `__CUSTOMER__`; an unknown placeholder stays as written. Subject and body are used for e-mail steps; a call or a task creates an agenda event for the deal owner with the subject as title and sends nothing.
3. **Enrol** a deal by entering its id. The sequence needs at least one step, and a deal cannot be running twice on the same sequence.
4. Turn the master switch on: `CRM_SEQUENCES_ON` = 1.

An enrolment stops by itself when the deal is won or lost, when someone presses **Stop**, when **Mark replied** is pressed, or, if the e-mail history is set up, when the customer's answer is recorded. After the last step it becomes done. The enrolment screen shows the status, the next step and when it is due; the journal lists every e-mail attempt with its result: `sent`, `simulated`, `draft` or `error`.

**E-mail steps: draft or send.** The setting "Sequence e-mail steps" decides what a due e-mail step does.

- **`draft` (the default).** Nothing is sent by the job. The step becomes a **planned e-mail** on the deal, owned by the deal owner, with the subject (the deal reference is appended in square brackets) and the suggested text. It appears in the deal's next steps, in CRM → Activities and on Home. The salesperson opens it, edits it, and sends it from Dolibarr, or from a mail client, then registers it. See the user guide, "E-mails suggested by a sequence". Dry-run does not apply to drafts, since nothing is sent; drafts do not count toward the per-run cap.
- **`send`.** The job sends the e-mail itself to the deal contact, copies the archive mailbox if one is set, and records the e-mail on the deal. Dry-run applies. Any value other than `send` means draft, so a typo never sends mail.

Start in `draft`. Move to `send` only for sequences whose text needs no human review, after a dry-run period whose journal you checked.

## E-mail history

The full exchange with a customer can be kept on the deal, as agenda events: the e-mails sent through the module, and the answers. The module has **no mailbox code**: Dolibarr's **E-mail collector** reads a dedicated mailbox, and the module adds one operation to it.

**Setup**

1. CRM → Setup → Parameters: set the **archive mailbox** (`CRM_ARCHIVE_MAILBOX`), a dedicated address, for example `crm@your-company.com`. Every customer e-mail sent for a deal is copied to it. `CRM_ARCHIVE_COPY` chooses a hidden copy (`bcc`, the default) or a visible copy (`cc`).
2. Home → Setup → Modules: enable the **E-mail collector** module. Create a collector for the archive mailbox: IMAP host and port, login and password, source folder. Use "Collect now" on the collector to test the connection.
3. Add **one operation** to the collector: **"CRM: record the e-mail on its deal"**. No other operation is needed.
4. The collector runs through the scheduled job "Email collector" (every 5 minutes by default). Check in Scheduled jobs that it is enabled, and that the system cron entry exists.
5. After installing or updating the module, **disable and re-enable CRM once** so Dolibarr registers its hooks (see [Maintenance](#maintenance)).

**What is kept.** A message read from the mailbox is recorded on a deal only when it can be tied to one:

1. its Message-ID, In-Reply-To or References carries the deal's tracking id (`dolibarr-crm<deal id>@…`) that the module puts on what it sends, which also covers the answers that thread on it; or
2. one of those ids is the Message-ID of a message already recorded on a deal, which covers a salesperson replying from their own mail client to an exchange; or
3. its subject carries the reference of a deal in square brackets, such as `[OPP2026-0001]`, which the module puts in the subject of every draft it suggests.

Any other message in the mailbox is **ignored**, without error: the mailbox is not a general inbox. A message already recorded is not recorded twice (the copy of a message sent from Dolibarr is recognised). The direction is "sent" when the sender is the module sender, an address in Dolibarr's `MAIL_FROM_EMAILS_TO_CONSIDER_SENDING`, or the e-mail of a Dolibarr user; otherwise "received". A received message stops the running sequences of the deal that stop on reply. An e-mail registered by hand as "sent manually" is replaced by the real message when it arrives.

**Limits to know and to tell the users**

- A hidden copy (Bcc) does not follow the customer's "reply all": the customer's answer reaches the mailbox only if a person copies or forwards it there. Use a visible copy (Cc) if the customer's replies should reach it by themselves, or if the subject reference alone should be enough (the customer's reply keeps `Re: … [OPP…]`).
- A message written from scratch, with no thread and no reference in its subject, cannot be tied to a deal.
- The text is stored in the event note as plain text; attachments are not stored.
- The events follow the Dolibarr agenda's visibility rules, not the CRM's rules, and they are personal data: decide how long you keep them.
- Anyone who writes a deal reference in a subject can attach a message to that deal, if it reaches the archive mailbox. Keep the address out of public lists.

## MCP

The module adds tools named `crm_*` to Dolibarr's MCP server, so an AI assistant can read and act on deals within the user's rights. Screens work whether or not the AI module is on.

1. Enable the **AI** module (Home → Setup → Modules).
2. The AI setup tabs "MCP server" and "Tool access control" show only when the constant `MAIN_FEATURES_LEVEL` is 1 or more (Home → Setup → Other setup). Without it, `ai/admin/server_mcp.php` can still be opened directly. `MAIN_FEATURES_LEVEL` also turns on every other experimental Dolibarr feature.
3. On the MCP server tab, switch the server on (`AI_MCP_ENABLED`). Then either generate the **shared MCP key** and choose the user the tools run as (`AI_MCP_USER_ID`), or use a **personal Dolibarr API key** for each user.
4. A user who connects with a personal API key needs the AI right "use the assistant". With the shared key, the tools run as the chosen user. Either way the tools list only what that user's CRM rights allow. Use a dedicated user with exactly the rights the assistant needs.
5. Tool access control (`AI_MCP_SERVER_ALLOWED_TOOLS`): while it is empty, every tool is allowed. Once it is set, the `crm_*` tools must be in the list.
6. A client calls `POST /ai/server/mcp_server.php` with `Authorization: Bearer <key>`: `initialize`, then `tools/list`, then `tools/call`.

Keys are secrets: never store them in the project, and regenerate one that was shared.

The tools cover pipelines, stages, sources, loss reasons and qualification items (list and change), deals (list, read, create, update, move, win, lose, delete), proposals links, qualification answers, agenda steps, sequences, reports and settings (`crm_get_settings`, `crm_set_settings`, `crm_run_sequences`, `crm_run_owner_reminders`). Tools that write return a confirmation text that names the record. The tools that change setup need "Configure CRM"; the others follow the matching right. Owners are numeric Dolibarr user ids: there is no user search tool. Searching and editing third parties, contacts, proposals, orders and invoices stays with the core tools.

## Maintenance

**After an update of the module.** Copy or `git pull` the new files; they are live at once. Then, if the update touched the descriptor (menus, rights, hooks, cron jobs, home box, the icon), **disable and re-enable the module** in Home → Setup → Modules. This is safe: tables, deals, constants and agenda events are kept, and nothing is seeded twice. Without it, new hooks (e-mail collector operation, search box entry) and new rights are not registered.

**Database changes.** There is no migration mechanism: tables are created at the first enable only. A release that adds a column needs the matching `ALTER TABLE` run by hand; the release notes say so.

**Disabling the module** removes the menus, rights, home box and the two scheduled jobs. It does **not** drop the `llx_crm_*` tables, does not delete agenda events (calls, meetings, e-mails stay in the agenda, but no longer link back to the deal while the module is off), and does not touch third parties, contacts, proposals or users. Enabling it again finds everything.

**Removing the data for good.** There is no purge button. To erase the CRM data, drop the `llx_crm_*` tables and delete the `CRM_*` constants yourself, after a backup. Agenda events with `elementtype = 'opportunity@crm'` stay unless you delete them.

**Backups.** Back up the Dolibarr database: the module stores nothing on disk. Mail credentials of the collector are in the database too.

**Watching it work**

- Home → Setup → Scheduled jobs: last run and result of the CRM jobs and of the e-mail collector.
- CRM → Sequences → a sequence → Journal: every e-mail attempt and its result.
- The Dolibarr log (`documents/dolibarr.log`) records the module's e-mail archive decisions (`CRM e-mail archive: recorded|duplicate|ignored|error`).
- The e-mail collector card shows what each collection did.

**Languages.** English and French are supplied (`langs/*/crm.lang`). Entries you create are shown as typed, in every language.

**Agenda event types.** E-mail events use the types "Send Email" and "Reception Email" of Dolibarr's agenda (`AC_EMAIL`, `AC_EMAIL_IN`) whether or not an administrator has activated them in Home → Setup → Dictionaries → Types of agenda events; calls use "Phone call" and meetings "Rendez-vous", which must be active, otherwise the event is recorded as "Other". The module never adds event types of its own.

## Settings reference

| Constant | Default | Meaning |
| --- | --- | --- |
| `CRM_ROTTING_DAYS` | 15 | Rotting days offered when adding a stage |
| `CRM_FORECAST_MONTHS` | 3 | Months covered by the forecast |
| `CRM_MAX_EMAILS_PER_RUN` | 50 | Cap of sent or simulated e-mails per job run |
| `CRM_MAIL_FROM` | empty | Sender; empty falls back to the Dolibarr sender |
| `CRM_SEQUENCES_ON` | 0 | Sequence job on or off |
| `CRM_SEQUENCE_EMAIL_MODE` | `draft` | `draft` or `send` |
| `CRM_DRY_RUN` | 1 | Send mode: journal only |
| `CRM_REMINDERS_ON` | 1 | Owner reminders on or off |
| `CRM_REMINDER_DRY_RUN` | 1 | Reminders: journal only |
| `CRM_REQUIRE_NEXT_STEP` | 1 | Refuse a stage change without a future next step |
| `CRM_ARCHIVE_MAILBOX` | empty | Archive mailbox address; empty means no copy |
| `CRM_ARCHIVE_COPY` | `bcc` | `bcc` or `cc` |
| `SEARCHFORM_POSITION_CRM` | 42 | Position of "CRM opportunities" in the general search box (optional, set in Other setup) |
| `MAIN_MODULE_CRM_ICON` | `fa-handshake` | Top menu icon; the theme reads it. Set at enable |

## Troubleshooting

| Symptom | Likely cause | What to do |
| --- | --- | --- |
| The module cannot be enabled | Third parties or Agenda is off, PHP below 8.1, Dolibarr below 21 | Enable the dependencies, check the versions |
| No **CRM** menu for a user | No CRM right | Grant "Read own deals" or "Read every owner" |
| A user does not see a colleague's deals | No "Read every owner" | Grant it, or the deal owner changes |
| Sequences, reminders or the collector never run | No system cron entry, or the job is disabled | Add the cron entry, check Scheduled jobs |
| A sequence runs but sends nothing | Draft mode (the default), or dry-run on, or the master switch off | Read the settings above; look at the journal |
| Nothing is actually sent | Dolibarr's e-mail setup is empty or wrong | Home → Setup → Emails, send a test message |
| Reminder or sequence e-mail "no address" | The owner or the deal contact has no e-mail | Fill it in; the company address is never a fallback |
| The collector offers no "CRM: record the e-mail" operation | The module was not re-enabled after the update | Disable and enable CRM once |
| E-mails reach the mailbox but nothing appears on the deal | The message cannot be tied to a deal (new thread, no reference in the subject) | Have it sent from a draft, or from a reply in the thread, with the reference kept |
| The same e-mail appears twice | Rare: a message registered by hand and a real one with a very different subject | Delete the entry registered by hand |
| The forecast is empty | No open deal with an amount and an expected close inside the window; won and lost deals are never forecast | Check the deals and `CRM_FORECAST_MONTHS` |
| The top menu shows a generic icon | `MAIN_MODULE_CRM_ICON` missing | Set it to `fa-handshake` and reload with a cleared cache |
| The MCP tabs are missing | `MAIN_FEATURES_LEVEL` is 0 | Set it to 1, or open `ai/admin/server_mcp.php` directly |
| An MCP client gets "MCP Server Disabled" | The AI module or `AI_MCP_ENABLED` is off | Enable them |
| An MCP tool is not listed | The user lacks the right, or the tool is not in the allowed list | Check the user's CRM rights and the tool access control |
