# CRM administrator guide

For the person who installs the CRM module, configures it, and keeps it running. Salespeople use [the user guide](USER_GUIDE.md). The design reasons behind the rules are in [STRUCTURE_ANALYSIS.md](STRUCTURE_ANALYSIS.md).

Contents: [Requirements](#requirements) · [Dolibarr settings the module relies on](#dolibarr-settings-the-module-relies-on) · [Install](#install) · [Rights](#rights) · [Module setup, page by page](#module-setup-page-by-page) · [Scheduled jobs](#scheduled-jobs) · [Sending e-mail](#sending-e-mail) · [Automated outreach](#automated-outreach) · [E-mail history](#e-mail-history) · [HTML e-mail](#html-e-mail) · [AI reply suggestions](#ai-reply-suggestions) · [MCP](#mcp) · [Maintenance](#maintenance) · [Settings reference](#settings-reference) · [Troubleshooting](#troubleshooting)

## Requirements

- Dolibarr 21 or later, PHP 8.1 or later. The module has been run and tested against the **Dolibarr 24.0.0** core and the **25.0.0 alpha** core (the e-mail drafts and archive, the HTML editor, pipelines, forecast, the AI reply block, the MCP tools). Versions 21 to 23 are accepted by the module descriptor but have **not been tested**. See "Version differences" in [MCP](#mcp) for what 24.0.0 does not offer.
- The Dolibarr modules **Third parties** and **Agenda** must be enabled: the CRM module depends on them, and Dolibarr will not enable it otherwise.
- Optional, each for one feature: **Proposals** (to link proposals to a deal), **AI** (MCP tools), **E-mail collector** (e-mail history), working **e-mail sending** (outreach, reminders).

## Dolibarr settings the module relies on

The module works only if these global Dolibarr settings are in place. Go through the list once, before you configure the module itself. "Needed for" says which feature breaks without it.

**Modules** (Home → Setup → Modules)

| Module | Needed for |
| --- | --- |
| **Third parties** | Required. Accounts, contacts, the account list in the deal form, the "New prospect" button |
| **Agenda** | Required. Next steps, calls, meetings and e-mail history are agenda events. Without it the module refuses to enable |
| **Scheduled jobs** (module `cron`) | The outreach job, the owner reminders and the e-mail collector. Without it Dolibarr never runs a scheduled job, whatever the system cron does |
| **Proposals** | Linking a proposal to a deal. Without it the Proposals section of the deal is simply absent |
| **E-mail collector** | Reading the archive mailbox (e-mail history). Not needed if you do not archive e-mails |
| **WYSIWYG editor** (module `fckeditor`) | The formatted (HTML) mode of the e-mail editor. Without it the HTML tab is a plain box where the HTML source must be typed |
| **Tags/Categories** (module `categorie`) | From v1.2.0: the colours under the account names and the categories chosen on the deal card, see [Categories of the accounts](#categories-of-the-accounts). Without it nothing about categories shows |
| **AI** | The MCP tools, and the "Suggest a reply with AI" block of the e-mail editor. Not needed for the screens |

**E-mail sending** (Home → Setup → Emails). Needed for reminders and for any real send from an outreach or from the "Send from here" button.

- Send method, SMTP server, port, login and password, encryption: set them for your provider, then use the test button of that page to send yourself a message. If this test fails, every send from the module will fail.
- **Sender e-mail** (`MAIN_MAIL_EMAIL_FROM`): used as the module sender when `CRM_MAIL_FROM` is empty. If that is empty too, the company e-mail (Home → Setup → Company) is used. One of the three must be set, or nothing can be sent.
- **Disable all e-mails** (`MAIN_DISABLE_ALL_MAILS`) must be **off**, otherwise every send fails with "Feature is disabled by option MAIN_DISABLE_ALL_MAILS".
- **Force the recipient** (`MAIN_MAIL_FORCE_SENDTO`), when set, redirects every e-mail to that address. Handy on a test instance, to be removed before going live, or customers receive nothing.
- **Automatic copy** (`MAIN_MAIL_AUTOCOPY_TO`), when set, adds a hidden copy to every e-mail Dolibarr sends, including those of the module. This is independent of the CRM archive mailbox.

**Users** (Users → each user)

- Each salesperson needs an **e-mail address** on their user card: it is where the owner reminders go, and it is how the e-mail history recognises a message "sent by us" (the sender's address belongs to a Dolibarr user).
- Each user's **language** decides which translation of the module they see. English and French are maintained by hand; the other languages Dolibarr supplies have a machine translation that no native speaker has reviewed. A language not supplied shows English.
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

### Time zone (from v1.2.0)

Dolibarr stores dates in the time zone of the server (UTC on many servers) and shows them in that zone unless the constant `MAIN_TZUSERINPUTKEY` is set to `tzuserrel` (Home → Setup → Other setup): each user then sees and enters times in the zone of their browser, with no change to what is stored (do **not** change `MAIN_SERVER_TZ` on a base that has data: it re-reads every stored date in the new zone). Set it if your salespeople are not in the zone of the server. Dolibarr takes the browser's zone from the login form into the session; a session that has none falls back to UTC, which showed the UTC hour in the next-step form. The pages of the CRM do not depend on that setting: `crmStartPage()` makes them use the zone of the session (in memory, for the request), so a server where `MAIN_TZUSERINPUTKEY` was never set still shows local times in the CRM; the rest of Dolibarr (the agenda, for example) needs the setting to do the same. A browser that reports UTC (some privacy settings do) gives UTC. The CRM pages therefore give a session that has no real zone (none, or UTC) the zone of the browser (a cookie `crm_tz`, accepted only if it is the name of a real time zone other than UTC, and one automatic reload of the page) or, failing that, the setting **Default time zone of the users** (`CRM_DEFAULT_TIMEZONE`, CRM → Setup → Parameters, for example `Europe/Brussels`; DST is handled), so the CRM does not depend on that. A user whose browser gives a real zone keeps it. The next-step form says under the date which zone the times are shown in ("UTC" means no zone was found: set the default). The hourly jobs of the module (reminders, outreach) use the zone of the server.

## Install

The module root is the directory that contains `core/modules/modCrm.class.php`. Dolibarr finds external modules in `htdocs/custom`, so install it there, preferably as a symbolic link to wherever the module lives:

```text
ln -sfn /path/to/crm /path/to/dolibarr/htdocs/custom/crm
```

The link name must be `crm`.

The complete installation procedure is in [INSTALL.md](../INSTALL.md); this section keeps what the first enable does.

`htdocs/custom` is scanned when `$dolibarr_main_document_root_alt` in `conf.php` is empty, or when it lists that directory (`$dolibarr_main_url_root_alt='/custom'` and the matching path). Nothing else in `conf.php` is needed.

Then, as an administrator:

1. Home → Setup → Modules, family **CRM**: enable the module. A top menu **CRM** appears.
2. [Grant rights](#rights) to your users.
3. Open CRM → Setup and read [Setup screen](#setup-screen).

**What the first enable does.**

- Creates the tables `llx_crm_*`.
- Creates the constants `CRM_*` with their defaults (see [Settings reference](#settings-reference)).
- Seeds, only when the entity has no pipeline: one **Commercial pipeline** with seven stages (New 10%, Replied to outreach 15%, Qualified 25%, Proposal sent 50%, Negotiation 75%, Won, Lost), seven lead sources (website, referral, inbound call, event, partner, prospecting, inbound e-mail), four qualification items (need, budget, decision maker, timeline) and six loss reasons (price, no budget, competitor, timing, no decision, no answer). Later enables do not seed again and do not reset your constants.
- Registers the menus, the rights, a home box ("CRM follow-up") and two scheduled jobs.
- Registers hooks (see [Maintenance](#maintenance)).

The seeded entries are stored in English and shown in the language of each user (English and French are maintained; other languages are machine translations). An entry you rename, or create, is shown exactly as you typed it.

## Rights

Users → Permissions, family CRM. A user can see and do only what the rights allow, in the screens and in the MCP tools.

| Right | Allows |
| --- | --- |
| Read own deals | See the deals they own, the pipeline, the activities. The minimum to use the module |
| Read every owner | See every owner's deals. Without it a user only ever sees their own |
| Create and update deals | Create and edit deals, move stages, qualify, link proposals |
| Delete deals | Delete deals (their agenda events are kept) |
| Create and complete next steps | Add, complete, write and send e-mail steps on a deal |
| Manage automated outreach | Create outreach and steps, enrol deals, stop, mark replied |
| Read CRM reports | The Reports page |
| Configure CRM | The Setup screen, and the MCP tools that change settings |
| Read the deals that have a step assigned to me (`assigned`) | **From v1.2.0.** The outside agent: open Home, Activities and the deal card for the deals that have a step of theirs not done, read only |
| Add and edit own notes on the deals I can see (`note`) | **From v1.2.0.** Add notes (and files to them) and edit their own notes, on the deals they can see |
| Create steps for myself or the deal owner, change and complete my own steps (`mystep`) | **From v1.2.0.** Create a step assigned to themselves or to the owner of the deal; reschedule and complete the steps assigned to them |

Typical profiles: a **salesperson** gets Read own, Create and update, Create and complete next steps. A **sales manager** adds Read every owner, Read CRM reports, and Manage outreach. Only you (or a delegated administrator) need Configure CRM and Delete.

**From v1.2.0**, the CRM adds a line and a block to the **Prospect** tab of a third party (the deals of the account, and its CRM history); the **Third party** tab is not changed. They need the right **Read own deals** or **Read every owner**, nothing else, and they only show deals that right allows. They use a new hook context of the module, `thirdpartycomm`: after updating the files, **disable and re-enable the module once** (see [Maintenance](#maintenance)), otherwise the card does not change. There is no setting and no schema change; disabling the module removes both.

### Outside agents (from v1.2.0)

An **outside agent** is a person hired to phone the prospects: a normal internal Dolibarr user who gets **only** the three CRM rights `assigned`, `note` and `mystep`, and **no right on Third parties** (nothing in the Third parties family, so they cannot open a third party or a contact card). Create the user, give them those three rights (alone, or through a group), and re-enable the module once after the update so that the rights and the reduced menu exist.

- **What they see.** The CRM menu shows only **Home** and **Activities**. Home lists **their own steps to do**, late ones first; Activities lists their steps only. A **deal** opens (from those links) only while it has **at least one step assigned to them that is not done**: once they complete their last one it leaves their view (their notes and steps stay in the history). On the card they see everything a read-only user sees (amount, stage, owner, source, categories, qualification, the notes, the message history, the outreach journal and the steps of everybody), the name of the account and of the contact **as text** (no link to the third party), and the contact's **phone, mobile and e-mail** to call and to check them. Every form of the card that changes the deal, the stage, the qualification, the proposals, the outreach or the categories is absent.
- **What they do.** **Complete** or **reschedule** (date and time only) the steps assigned to them; **create a step** assigned to themselves or to the owner of the deal (nobody else); **add notes** (with files) and edit their **own** notes. They cannot delete a step, change the title, kind or assignee of one, send e-mails, or touch the pipeline.
- **Assigning a step.** The form that creates a step has a field **Assignee** (the owner of the deal by default). A user with the right *Create and complete next steps* may choose any user who may change steps (that right or `mystep`); the agent only themselves or the owner. The same field is in the edit form for the salespeople, and the tables of steps (deal card, Activities, Home) have an **Assignee** column.
- **E-mail to the assignee.** When somebody assigns a step to another person, that person gets an e-mail (deal, step, date, link), in their language: setting **Tell the assignee of a step by e-mail** (`CRM_ASSIGN_NOTIFY`, on by default). Like the owner reminders it follows the **reminder dry-run** (`CRM_REMINDER_DRY_RUN`, on by default): **while it is on the e-mail is only written to the log**. Switch the dry-run off, once the sender and the staff addresses are right, to send them.
- **MCP.** An agent's assistant can: list their steps (`list_crm_activities`), read one deal, its history, its notes and its messages, add a note, create a step for themselves or the owner (`assignee_id`), reschedule and complete their steps. The `delete_*` tools stay behind the general setting, and everything else needs the other rights. Each tool declares the right the caller holds (`read` or `assigned`, `write` or `note`, `activity` or `mystep`).
- **Visibility is a query.** The rule "a deal with a step of mine not done" is in `crmOwnerSql()` and `crmCanReadOwner()`, so the lists, the cards, the notes, the files and the MCP tools agree.

Two more rights matter outside CRM: creating a prospect from the deal form needs the Dolibarr right to create third parties, and the general search box and the account list only show third parties the user may read.

## Module setup, page by page

**Explanations are hidden by default.** On every page, a text of more than 12 words that explains something (a list, a field, a rule) is hidden until the **"?" button** at the top right of the page title is clicked; click again to hide it. Warnings, errors, confirmations and the notice of the e-mail editor that says which AI service the text goes to, and whether names are masked, are always visible. The About tab is page content and stays visible. This is also the place where the setup explanations of this guide appear on screen (the notes under the Parameters table, the help under each setting, the notes on pipelines).

Everything the module lets you configure is under **CRM → Setup** (needs "Configure CRM"), plus the **Automated outreach** screens. The gear icon of the module in Home → Setup → Modules opens the same Setup screen.

### CRM → Setup → Parameters

The settings that are only on or off are **on/off switches** (the same switches as elsewhere in Dolibarr): click one and it is saved at once, without the Save button, and it is stored as `1` or `0`. Switching **dry-run** off (outreach or reminders) or the **AI assistant** on asks for a confirmation first, because real e-mails will be sent or text will leave for an external service. The other settings are fields; press **Save** once for all of them. Clicking a switch does not save what you typed in the fields, so save those first. A field left empty counts as off or default (for the mail sender: use the Dolibarr sender). Changing a switch needs the "Configure CRM" right, not a Dolibarr administrator.

| Field | Constant | What it does |
| --- | --- | --- |
| Default rotting days for a new stage | `CRM_ROTTING_DAYS` (15) | The value offered in the "rotting days" field when you add a stage (Stages tab). A deal is **rotting** when it is open and its latest completed activity (or its creation, when none) is older than the stage's rotting days. It is a warning on the board and on Home, never an automatic move. A stage with 0 never rots |
| Forecast window (months) | `CRM_FORECAST_MONTHS` (3) | How many months ahead the forecast on Home and in Reports covers |
| Maximum e-mails per cron run | `CRM_MAX_EMAILS_PER_RUN` (50) | Stops one run of the outreach job after this many sent or simulated e-mails; the rest wait for the next run. Drafts do not count |
| Sender address | `CRM_MAIL_FROM` (empty) | Sender of the customer e-mails and of the reminders. Empty: the Dolibarr sender (`MAIN_MAIL_EMAIL_FROM`), then the company e-mail. The e-mail editor offers it as the default of a list of senders and warns when none is set |
| Automated outreach enabled | `CRM_SEQUENCES_ON` (0) | Master switch of the outreach job. While 0 no step is run, draft or send |
| Outreach e-mail steps | `CRM_SEQUENCE_EMAIL_MODE` (`draft`) | A drop-down: **Draft for the salesperson** (default) or **Send automatically**. See [Automated outreach](#automated-outreach) |
| Outreach dry-run | `CRM_DRY_RUN` (1) | In send mode, journal only: nothing is sent and nothing is copied to the archive. Not used in draft mode |
| Owner reminders enabled | `CRM_REMINDERS_ON` (1) | The daily digest e-mail to each deal owner: overdue next steps, deals with no next step, deals past their expected close. At most one per owner per day |
| Reminder dry-run | `CRM_REMINDER_DRY_RUN` (1) | Journal only for the reminders. Switch to 0 when the journal looks right |
| Require a future next step before an open stage change | `CRM_REQUIRE_NEXT_STEP` (1) | While 1, a deal cannot be moved from one open stage to another unless it has an unfinished agenda event dated today or later. Moving to Won or Lost never needs it. Set 0 to allow free moves |
| AI assistant in the e-mail editor | `CRM_AI_EMAIL_ASSIST` (0) | **Off by default**, because the exchange goes to an external provider. `1` shows the "Suggest a reply with AI" block in the e-mail editor when the AI module is enabled; `0` hides it. See [AI reply suggestions](#ai-reply-suggestions) |
| Default AI prompt | `CRM_AI_EMAIL_PROMPT` (empty) | A text area. The prompt offered to salespeople, who can edit it for each request. Empty uses the built-in prompt (English or French by the user's language) |
| Archive mailbox address | `CRM_ARCHIVE_MAILBOX` (empty) | The dedicated address copied on every customer e-mail sent for a deal. Empty: no copy |
| Copy mode for the archive mailbox | `CRM_ARCHIVE_COPY` (`bcc`) | A drop-down: **Hidden copy (Bcc)** or **Visible copy (Cc)** |
| Web address of internal references (prefix) | `CRM_INTERNAL_REF_URL` (empty) | From v1.2.0. An http or https address, for example `https://tracker.example.com/browse/`. The **Internal reference** of a deal (the number of its task in another system) is shown as a link made of this address followed by the reference (URL-encoded), opened in a new tab with `rel="noopener noreferrer"`. Empty: the reference is plain text. Other schemes (`javascript:`, `data:`...), blanks, quotes and angle brackets are refused |
| Allow MCP tools that delete CRM records | `CRM_MCP_ALLOW_DELETE` (0) | From v1.2.0. Off: every `delete_*` MCP tool is not offered and refuses. On: MCP clients with the matching right can use them. Only changeable here, never by MCP |
| Ask the prospect to confirm the "not interested" link | `CRM_OPTOUT_CONFIRM` (1) | From v1.2.0. On: the public page shows a button and records the request only when it is pressed. Off: opening the link records it at once. See [The public "not interested" link](#the-public-not-interested-link) |

Under the table a note reminds you that outreach stay off until enabled and that dry-run is on for outreach and reminders, and a longer help text explains the e-mail history setup.

### CRM → Setup → Stages

Pipelines and their stages. From top to bottom:

1. **Pipeline list and Filter button.** Picks the pipeline whose stages are shown and edited below. It lists inactive pipelines too.
2. **Stage table.** For the selected pipeline, in board order: colour, label, position, probability, forecast category (and the won / lost flag), with three icons per row: **Modify** (the row becomes a form with the same fields as the **Add** form below; **Save** or **Cancel**), **Turn off / Turn on** and **Delete**. A turned-off stage is greyed and marked *Inactive*. It is no longer offered as a target for a move and no longer shown on the board once no deal is in it; the deals it holds stay there, visible on the board and on their card, until someone moves them. Delete asks for a confirmation and is refused for a stage that holds a deal or that any deal has passed through (the cohort report relies on that history): turn it off instead. A stage cannot be both won and lost, and needs a label.
3. **Selected pipeline** (a warning appears if the pipeline has no open stage). Shows the fixed reference, and lets you change the **label** and the **Active** checkbox, then **Save**. An inactive pipeline is no longer offered for new deals or on the board; its deals stay in the lists and reports. The last active pipeline cannot be deactivated.
4. **Add** (a stage to the selected pipeline). The fields, in order:
   - *Label*: the name shown on the board.
   - *Position*: a number; stages are shown in ascending order. Leave gaps (0, 10, 20…) to insert later.
   - *Colour*: `#rrggbb`. The top border of the column and the stage badge. An invalid value falls back to grey.
   - *Probability* (%): the chance of winning at this stage, 0 to 100. A deal has no percentage of its own; the weighted forecast is amount × stage probability.
   - *Rotting days*: see the Parameters tab. 0 disables the warning.
   - *Forecast category*: `pipeline`, `upside` or `commit` for open stages (the forecast groups by it: commit is what you would bet on); `closed` for a won stage; `lost` for a lost stage. Won and lost deals are never forecast.
   - *Reply stage* checkbox: the stage a deal moves to when the prospect replies ("Replied to outreach" by default). A pipeline has one: ticking it on a stage unticks it on the others, and it must be an open stage (not won or lost). See "Prospects who reply" below.
   - *Won stage* / *Lost stage* checkboxes. A deal moved into a won stage becomes **won**: it gets its closing date and leaves the board and the forecast. A deal moved into a lost stage becomes **lost** and **requires a loss reason**. Tick neither for an open stage.
5. **New pipeline.** Reference (letters, digits, underscore, dash; stored in capitals; unique; never changes), label, and **Copy the stages of**: pick a pipeline to start from its stages, probabilities, colours and flags, or "Nothing (empty pipeline)". Create, and the page opens the new pipeline.

**Prospects who reply.** When the answer of a prospect is recorded on an open deal, the deal moves by itself to the **reply stage** of its pipeline, so that it stands out on the board as a lead that answered and waits for qualification. Two things record an answer: the e-mail collector, when it reads an incoming message that belongs to the deal (see [E-mail history](#e-mail-history)), and "Mark replied" on an enrolment of an automated outreach (the MCP tool `update_crm_opportunity_replied` does the same). The same event also stops the running outreach of the deal that have the rule "stop when the contact replies". The deal moves only when it is still **before** the reply stage in the pipeline: a deal that is already at that stage or beyond it (Qualified, Negotiation...) or closed stays where it is, and a message the salesperson sent does not move anything. The move is written in the stage history, and the rule "a future next step is needed to change stage" does not apply to it, since nobody dragged the card: the deal then shows in "no next step" on Home, which is the prompt to qualify it. The setting "Move a deal to the reply stage when the prospect replies" (`CRM_REPLY_MOVES_DEAL`, on by default, a switch of the Parameters tab) turns the whole thing off. A pipeline without an active reply stage does not move anything. A pipeline created by hand has no reply stage until you tick the box on one of its stages.

Rules when you design stages: a pipeline needs at least one **open**, one **won** and one **lost** stage; a new deal starts in the first open stage by position, and without one nobody can create a deal in that pipeline. Typical shape: New → Qualified → Proposal sent → Negotiation → Won / Lost, with probabilities rising from 10% to 75%.

Changing the probability of a stage applies at once to every deal in it: the weighted forecast always reads the stage, never a copy. A warning above the pipeline form tells you when turning a stage off leaves the pipeline without an open stage.

### CRM → Setup → Qualification

The checklist a salesperson ticks on each deal. A table lists every item (code, label, points, position, how many deals have an answer for it) with the icons **Modify**, **Turn off / Turn on** and **Delete**, and a form adds one: **code** (unique, letters and digits, for example `NEED`), **label** (shown to users), **points** (default 25) and **position**. The score of a deal is the sum of the points of the ticked items, capped at 100; the card lists the unticked items as "missing". Keep the total around 100 so the score reads as a percentage. The seeded items are Need, Budget, Decision maker, Timeline, 25 points each. There is no scoring-rule engine and no grade.

A turned-off item is no longer asked on the deals and no longer counts in the score, so scores of existing deals change when you turn one off or change its points. An item that has recorded answers cannot be deleted (the Delete icon is not shown): turn it off.

### CRM → Setup → Loss reasons

The reasons offered when a deal is closed as lost, which is **required** to close it. The table has the same columns and icons as the qualification one, and the same form without the points (code, label, position). The Reports page counts deals and amounts per reason, so keep the list short and distinct. Seeded: price, no budget, competitor, timing, no decision, no answer.

### CRM → Setup → Sources

Where a lead came from, offered on the deal and used by the "by source" report. Same table and form as the loss reasons. Seeded: website, referral, inbound call, event, partner, prospecting, inbound e-mail.

For these three lists a **turned-off** entry is no longer offered, but the deals that already use it keep it (a source stays on the card and in the "by source" report; a loss reason stays in the loss report). An entry used by a deal cannot be deleted: turn it off. An unused one is deleted after a confirmation. The code must be unique in its list: the screen refuses a code already used. Renaming an entry that was seeded stops its automatic translation: the label is then shown exactly as you typed it. The MCP tools `create_or_update_crm_qualification`, `create_or_update_crm_loss_reason` and `create_or_update_crm_source` change the same data and leave the on/off state alone when `active` is not given.

### CRM → Setup → About

A short note on what the module keeps in Dolibarr and what disabling it leaves behind.

### CRM → Automated outreach

Not in the Setup screen, but it is configuration, and it needs "Manage automated outreach". The list (reference, label, active, number of steps) has a form to create an outreach: **label** and **Active** checkbox. The reference is generated at first (`SEQ0001`, `SEQ0002`…) and can be changed later. Open an outreach to change it (reference, label, active, stop rules), manage its steps and enrolments and read its journal: see [Automated outreach](#automated-outreach). An inactive outreach runs nothing.

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
| CRM automated outreach (`CrmCustomerSequences`) | Runs the due steps of the enrolled deals. Does nothing while `CRM_SEQUENCES_ON` is off |

In Scheduled jobs you can start any job by hand ("Execute now"), which is the quickest way to test one. The last run date and result are shown there. A job whose last run is empty has never been called: check the cron entry.

## Sending e-mail

Two parts of the module can send mail: the **reminders** (to the deal owner) and the **outreach** (to the deal contact, only in `send` mode, see below). Both use Dolibarr's own mail settings.

1. Home → Setup → Emails: choose the send method, the SMTP server and login, and send a test message from that page. Until this works, no real send can.
2. Set `CRM_MAIL_FROM`, or leave it empty to use the sender configured in Dolibarr.

Customer e-mails go to the **contact** of the deal, never to the company address. A deal with no contact address gets no automatic e-mail.

Dry-run keeps the first runs harmless: with dry-run on, a run only writes the journal (reminders: `llx_crm_reminder_log`; outreach: the outreach's journal) and nothing is sent. Check the journal, then switch dry-run off.

## Sender of the e-mails written in the editor

In `email_compose.php` the salesperson picks the address "Send from here" sends from, among at most three:

1. the **default** sender of the CRM (the setting "Sender address", else the sender of the Dolibarr e-mail setup, else the company e-mail, see the Parameters tab);
2. the **e-mail of the user**, from their profile (offered only when it is a valid address);
3. the **mailbox of the lead e-mail collector**: the login of each active collector of the E-mail collector module that has the operation "Create a project/lead" (or the reference of the example `Collect_Leads`), when that login is an e-mail address. A collector whose login is not an address (a user name) is not offered.

An address offered twice is listed once. Nothing else can be chosen: the form sends a code, and the server looks it up in this list, so no other address can be forced through the form. The choice applies to that message (the recorded e-mail names it as sender) and is kept in the user's own settings (`CRM_MAIL_FROM_CHOICE`, in `llx_user_param`) as the preselection of the next e-mail. The outreach and the owner reminders always use the default sender.

**Copy to the sender.** The editor has a box "Send me a hidden copy (Bcc)", ticked by default (it is shown only when the user has a valid e-mail address in their profile). Ticked, "Send from here" adds that address as a Bcc, next to the archive mailbox (`CRM_ARCHIVE_MAILBOX`, as Cc or Bcc by `CRM_ARCHIVE_COPY`); an address that would get two copies gets one. The copy is not recorded anywhere else: the event on the deal names the real recipient only. Outreach and reminders never copy anyone.

Your mail server decides whether it accepts a sender: sending from a mailbox that the SMTP account may not use is refused, or accepted and marked as spam (SPF, DKIM). Test each address once. A reply goes to the sender's mailbox: sent from the lead mailbox, it is read by the collector (the reply is recorded on the deal when the archive mailbox is that mailbox, see [E-mail history](#e-mail-history)); sent from a personal address, it is only recorded if the salesperson's mailbox is also read by the collector.

## Automated outreach

Automated outreach is made for prospecting at scale: a prospect that was picked beforehand is enrolled, one deal at a time, and receives the planned series of e-mails, calls and tasks; there is no bulk enrolment. As soon as the prospect replies, the outreach stops (rule "stop when the contact replies") and the deal moves to the reply stage of its pipeline for the salesperson to qualify. CRM → Automated outreach (needs "Manage automated outreach"). The explanation at the top of the list says the same, behind the "?" button.

1. Create the outreach (label, active). Open it. At the top of the card, a form lets you **change it later** with the **Update** button: the reference (short code, unique, letters, digits, underscore and dash, 32 characters at most; the generated `SEQ0001`-style reference can be replaced by a meaningful one), the label, the **Active** flag, and the three **stop rules** (stop an enrolment when the contact replies, when the deal is won, when the deal is lost). Changing these does not touch the steps or the enrolments: a running enrolment goes on under the new rules, and the enrolments of an outreach made inactive simply wait, then resume when it is active again (steps already due then run at the next job run). The MCP tool `update_crm_sequence` does the same.
2. Add **steps** in the order they should run. Each step has a kind (**e-mail**, **call**, **task**), a **delay in days after enrolment** (not after the previous step), a subject and a body. Subject and body accept the placeholders `__DEAL__`, `__REF__`, `__AMOUNT__`, `__CONTACT__`, `__CUSTOMER__`; an unknown placeholder stays as written. The add-step form has the kind, the delay, the e-mail mode and the subject on the first line, and the text of the message below them. For an e-mail step the text is **plain or formatted (HTML)**, chosen with the same **Format** switch and tabs as the e-mail editor: a WYSIWYG editor for HTML, with the plain-text version shown read-only, made from the HTML when the message is sent. The text of a step is stored as written (HTML or plain) and a step in HTML is marked "(HTML)" in the steps table. In HTML, the values that replace the placeholders (a contact or company name, for example) are inserted as text, so a `<` or `&` in a name cannot add markup. A draft made from an HTML step opens in HTML in the e-mail editor, and an automatic send goes out as HTML with a plain-text part. The kind selector hides the text and the mode for a call or a task, which have no message. The MCP tool `add_crm_sequence_step` takes the body as given: HTML or text.

**AI help for the text of a step.** Next to "Add step", an **AI** button ("Suggest a text with AI") writes the text of an e-mail step. It is **enabled only when** the AI module is enabled, the AI service is configured (a key for the services that need one, an address for the "custom" service), the CRM setting "AI assistant in the e-mail editor" is on, the step is of the e-mail kind and the subject is filled in; otherwise it stays disabled, with a tooltip saying why. It asks for the text in the chosen **format** (HTML fragment or plain text) and puts it in the text box. If the box already holds a text, the button reads "Improve the text with AI": the AI is asked to improve that text, keeping its meaning, placeholders and language, and its answer **replaces** the text in the box. The prompt tells the AI which placeholders exist (`__DEAL__`, `__REF__`, `__AMOUNT__`, `__CONTACT__`, `__CUSTOMER__`) and asks it to keep them as written. **Only the subject and the text of the template are sent**: a template holds placeholders, not data about a deal, so no customer data leaves and the privacy redaction has nothing to mask. Nothing is saved until you press "Add step", and the answer is cleaned (no script, style or event attributes in HTML). A step added this way is an ordinary step. Subject and body are used for e-mail steps; a call or a task creates an agenda event for the deal owner with the subject as title and sends nothing.
3. **Enrol** a deal either from the card of the deal (section "Automated outreach": pick the outreach, "Enrol from now"; one outreach at a time, open deals only, active outreach with at least one step; needs the right "Manage automated outreach") or by choosing it in the list under "Enrolments" of the outreach: it is a searchable list, type part of the reference, the title or the account name to filter it. The list offers the open deals you may see (up to 1 000) that are not already running on this outreach. The outreach needs at least one step, and a deal cannot be running twice on the same outreach.
4. Turn the master switch on: `CRM_SEQUENCES_ON` = 1.

An enrolment stops by itself when the deal is won or lost, when someone presses **Stop**, when **Mark replied** is pressed, or, if the e-mail history is set up, when the customer's answer is recorded. After the last step it becomes done. The enrolment screen shows the status, the next step and when it is due; the journal lists every e-mail attempt with its result: `sent`, `simulated`, `draft` or `error`.

**The journal keeps the e-mail.** Each line records the e-mail as the step made it: the subject and the text with the placeholders replaced, the format (plain text or HTML), the address it goes to and the address it is sent from, the position of the step and the day of the outreach (counted from the enrolment) on which it ran. This is stored whatever the outcome, including in dry-run and for drafts, so a simulation can be reviewed before dry-run is turned off. The eye icon at the end of a line opens the e-mail (`sequence_log_view.php`): a simulated one is marked as not sent, a draft as a text that the outreach wrote and that the planned e-mail may have outgrown, an error with its reason. The same lines are listed on the deal card, under History → "E-mails from automated outreach", with the outreach, the step and the day that caused each one. Anyone who may read the deal may read its e-mails. A line written before the content was kept is completed when it is opened and saved from then on: for a sent e-mail, from the copy recorded on the deal; otherwise rebuilt from the step and the deal **as they are today** and marked as rebuilt, since it can differ from what was sent or planned; a line whose step has been deleted has nothing to rebuild. The content columns come with schema version 2: after updating the files, disable and enable the module (until then the journal works as before and the page says that the e-mails are not kept yet).

**E-mail steps: draft or send.** The setting "Outreach e-mail steps" decides what a due e-mail step does.

- **`draft` (the default).** Nothing is sent by the job. The step becomes a **planned e-mail** on the deal, owned by the deal owner, with the subject (the deal reference is appended in square brackets) and the suggested text. It appears in the deal's next steps, in CRM → Activities and on Home. The salesperson opens it, edits it, and sends it from Dolibarr, or from a mail client, then registers it. See the user guide, "E-mails suggested by an outreach". Dry-run does not apply to drafts, since nothing is sent; drafts do not count toward the per-run cap.
- **`send`.** The job sends the e-mail itself to the deal contact, copies the archive mailbox if one is set, and records the e-mail on the deal. Dry-run applies. Any value other than `send` means draft, so a typo never sends mail.

**The mode of each e-mail step.** The global setting is only the default. When you add an e-mail step, the **E-mail mode** list of the form lets you choose **Default** (follow the global setting), **Draft for the salesperson**, or **Send automatically** for that step alone. The steps table shows each step's mode, and "Default (draft)" or "Default (send)" for the ones that follow the global setting. This lets one outreach mix a reminder that is sent by itself and a long message that a person must review. A call or task step has no mode. The MCP tool `add_crm_sequence_step` takes the same choice (`send_mode`: empty, `draft` or `send`), and `get_crm_sequence` returns it. A step whose mode is "send" is subject to dry-run and to the cap of e-mails per run; drafts are not.

**Changing a step.** Each row of the steps table has two icons: a **pencil** (Modify) and a **trash can** (Delete); hover one to see its name. The pencil opens the same form as for adding, filled with the step (kind, delay, e-mail mode, subject, and the text with its format, plain or HTML, and the AI button), and the **Update step** button saves it; **Cancel** leaves it unchanged. The step **keeps its position**, so the order of the outreach does not change. What happens to enrolments that are already running (nothing is recomputed, so nothing fires early or late by surprise):

- An enrolment that is **waiting for the step you change keeps the due date it already has**. A new **delay** therefore applies only to steps scheduled from now on: the enrolments that have not reached that step yet, and the following steps of those that have.
- The **kind, subject, text and mode** are read when the step runs, so they apply the next time it runs for every enrolment, including those already waiting for it.
- Do not **delete** a step that enrolments are waiting for: they cannot find their next step and are marked finished. Change it instead. The screen asks for a confirmation and tells you how many running enrolments wait for the step.

There are no buttons to move a step up or down; the order is the order of creation. The MCP tool `update_crm_sequence_step` changes only the fields it is given, in the same way.

Start every step in `draft`. Move a step to `send` only when its text needs no human review, after a dry-run period whose journal you checked.

## E-mail history

The full exchange with a customer can be kept on the deal, as agenda events: the e-mails sent through the module, and the answers. The module has **no mailbox code**: Dolibarr's **E-mail collector** reads a dedicated mailbox, and the module adds one operation to it.

**Setup**

1. CRM → Setup → Parameters: set the **archive mailbox** (`CRM_ARCHIVE_MAILBOX`), a dedicated address, for example `crm@your-company.com`. Every customer e-mail sent for a deal is copied to it. `CRM_ARCHIVE_COPY` chooses a hidden copy (`bcc`, the default) or a visible copy (`cc`).
2. Home → Setup → Modules: enable the **E-mail collector** module. Create a collector for the archive mailbox: IMAP host and port, login and password, source folder. Use "Collect now" on the collector to test the connection.
3. Add **one operation** to the collector: **"CRM: record the e-mail on its deal"**. No other operation is needed.
4. The collector runs through the scheduled job "Email collector" (every 5 minutes by default). Check in Scheduled jobs that it is enabled, and that the system cron entry exists.
5. After installing or updating the module, **disable and re-enable CRM once** so Dolibarr registers its hooks (see [Maintenance](#maintenance)).

**What carries the deal in an e-mail (from v1.2.0).** A message sent **by the platform** ("Send from here" of the editor, an automated outreach) carries the deal in its headers, not in its subject: the `Message-ID` ends in `dolibarr-crm<deal id>@host` and Dolibarr adds `X-Dolibarr-TRACKID: crm<deal id>@host` (and a `Feedback-ID`). The customer's answers quote the Message-ID in `In-Reply-To` and `References`, so they find the deal without any marker; the subject of what is sent from the editor has the marker **taken out** (the draft shows it, because the same text can be sent from a mail program). A message written **in a mail program** has no such header: it is found by the short marker `[O2026-0001]` that the draft puts in its subject, or, with no marker, by the address of the deal's contact (see rule 4 below). The marker is therefore only needed to name a deal when the contact has several open ones.

**What is kept.** A message read from the mailbox is recorded on a deal only when it can be tied to one:

1. its Message-ID, In-Reply-To or References carries the deal's tracking id (`dolibarr-crm<deal id>@…`) that the module puts on what it sends, which also covers the answers that thread on it; or
2. one of those ids is the Message-ID of a message already recorded on a deal, which covers a salesperson replying from their own mail client to an exchange; or
3. its subject carries the **marker** of a deal in square brackets, which the module puts in the subject of every draft it suggests: from v1.2.0 a short `[O2026-0001]` (the reference `OPP2026-0001` without its `PP`, which tells the recipient nothing about a pipeline); the long `[OPP2026-0001]` of the earlier versions is still recognised; or
4. **From v1.2.0**, none of the above, but the other end of the exchange is the **contact of an open deal**: the sender of a received message, or a recipient (To or Cc) of a message sent by us, has the e-mail address of the contact set on the deal. So a salesperson who writes to a contact from any mail client with the archive mailbox in Cc or Bcc, or a contact who writes to the archive mailbox, gets the message in the history without any marker. Only the contact of the deal counts here (not the other contacts or the address of the third party). Only open deals are searched, and when the contact has **several open deals** the message is ignored, because the CRM cannot tell which one it belongs to: use the reference in the subject then. The checks below (known address, authentication) apply as for the other rules. No setting and no schema change.

A reference or an id can be guessed and a From header can be forged, so finding the deal is not enough: the message is also **ignored unless it comes from, or (for a message sent by us) goes to, an address the deal knows**: the contact of the deal, the other contacts of its third party, the e-mail of the third party, or a colleague at the same company domain (a public mail domain such as gmail.com never counts as a company domain). A deal without a third party therefore keeps no message. A message that your mail server marks as failing SPF, DKIM or DMARC (its `Authentication-Results` header) is ignored; with the setting **Archive only authenticated e-mails** (`CRM_ARCHIVE_REQUIRE_AUTH`), a message without a passed check is ignored too: turn it on only if your server adds that header (test with a real message). An automatic message (bounce, out-of-office reply, mailing list: headers `Auto-Submitted`, `Precedence`, `List-Id`, an empty `Return-Path`…) is kept but does not stop the outreach or move the deal.

Any other message in the mailbox is **ignored**, without error: the mailbox is not a general inbox. A message already recorded is not recorded twice (the copy of a message sent from Dolibarr is recognised). The direction is "sent" when the sender is the module sender, an address in Dolibarr's `MAIL_FROM_EMAILS_TO_CONSIDER_SENDING`, or the e-mail of a Dolibarr user; otherwise "received". A received message stops the running outreach of the deal that stop on reply. An e-mail registered by hand as "sent manually" is replaced by the real message when it arrives.

**Limits to know and to tell the users**

- A hidden copy (Bcc) does not follow the customer's "reply all": the customer's answer reaches the mailbox only if a person copies or forwards it there. Use a visible copy (Cc) if the customer's replies should reach it by themselves, or if the subject reference alone should be enough (the customer's reply keeps `Re: … [O…]`).
- A message written from scratch, with no thread and no reference in its subject, cannot be tied to a deal, except (from v1.2.0) when its other end is the contact of exactly one open deal.
- The text is stored in the event note as plain text; attachments are not stored.
- The events follow the Dolibarr agenda's visibility rules, not the CRM's rules, and they are personal data: decide how long you keep them.
- The sender check lowers the risk of a forged message but cannot remove it: a person who knows a deal reference and can spoof an address the deal knows (a colleague at the customer's domain) can still attach a message if your server does not verify senders. Keep the archive address out of public lists, and turn on the authentication setting when your server supports it.

## HTML e-mail

The e-mail editor (Write / send, on a planned e-mail) can send **plain text** or **formatted (HTML)** messages. Dolibarr's mail library supports both, and the module uses it.

**What the salesperson sees.** A **Format** choice (formatted HTML with a plain-text version, or plain text only) and two tabs. In HTML format the first tab is a WYSIWYG editor (Dolibarr's own, CKEditor, with its e-mail toolbar: bold, italic, lists, links, colours, tables) and the second tab shows the **plain-text version, read-only and automatic**. In plain-text format the second tab is the editor. Switching to HTML turns the text into paragraphs; switching back to plain text asks for confirmation, because the formatting is lost. A draft keeps its format: a draft written in HTML opens in HTML.

**Why one message in two forms, and not two texts.** The standard practice is a `multipart/alternative` message: a plain-text part and an HTML part. Dolibarr's mail library builds the plain part itself from the HTML; it has no way to take a separately written text. Letting a person edit both would also let them disagree. So the module makes the plain version from the HTML, and shows it in the tab so nobody is surprised. To keep it readable, the module adds line feeds after paragraphs, line breaks and list items in the HTML it sends: this changes nothing on screen, but lets the plain version keep its paragraphs. A **link address is not repeated** in the plain version: tell users to write an address in the text when it matters.

**What is sent.** The message goes out as `multipart/alternative` with the plain part first and the HTML part second, wrapped in the usual Dolibarr HTML page (which carries Dolibarr's e-mail CSS). The Dolibarr send methods "mail" and "SMTP socket library" add the alternative part only when the constant `MAIN_MAIL_USE_MULTI_PART` is set; Swift Mailer always adds it. Dolibarr sets that constant to 1 by default unless an administrator overrides it, and the module also sets it **in memory for each HTML message** and restores it afterwards, so an instance where it was switched off still sends the plain part. Nothing has to be changed. Reply-to, tracking id, archive copy and the e-mail history work as for plain text.

**What is stored.** The text of the e-mail is kept in the note of the agenda event, as HTML for a formatted message, which Dolibarr's agenda displays properly. The AI assistant and the e-mail history read it as text.

**Safety.** What a user types is cleaned by Dolibarr (scripts and event attributes are removed) before it is saved or sent; the editor also restricts what can be entered.

**Limits.**

- **No images.** There is no upload or inline-image feature in the module's editor. Images can only be remote links, which many mail programs block by default. Keep to text and links.
- "**Open in my mail client**" can only pre-fill plain text (a limit of the `mailto:` link). For a formatted message, **Copy text** puts the formatted version in the clipboard, to paste into the mail client.
- The e-mail collector stores a received HTML message as plain text.
- If the WYSIWYG module is off, the HTML tab is a box showing the HTML source.

## Categories of the accounts

**From v1.2.0.** The CRM shows and uses the **categories of third parties** of Dolibarr's Tags/Categories module (type Customers/Prospects). Nothing is copied: no table, no field of the CRM.

- **Setup.** Enable the module, create the categories in Tools → Tags/Categories → Customers/Prospects, each with its **colour**. A category with a colour underlines the account name wherever the CRM prints it (kanban cards, opportunity list, deal card): one coloured band per coloured category, three at most, side by side under the name, with all the labels in the tooltip. The deal card also shows the labels as coloured badges, the language of the third party as a small badge (FR, NL...), and a pencil to choose the categories.
- **A one-answer degree.** In CRM → Setup → Parameters, **Mutually exclusive category groups** (`CRM_CATEGORY_SINGLE`, a list of check boxes of the categories that have sub-categories) takes one or more parent categories (for example "Interest" with Hot, Warm, Cold). On the deal card their sub-categories are radio buttons (plus "None"), so an account has at most one of them; the parent itself is only a heading and cannot be chosen. The other categories are checkboxes.
- **Filter.** The deal list and the kanban have a category filter (`catid`); choosing a parent includes its sub-categories.
- **Rights.** Choosing categories from the deal card needs the CRM right **Create and update deals** and a deal the user may see (own deals, or all with "Read every owner"). It needs **no Dolibarr right on categories**, on purpose: "Create/modify categories" opens all the categories of all the third parties. Give the salespeople **Read categories** only, so that Dolibarr's own cards show the tags; leave "Create/modify categories" to the administrator, who keeps the names and colours. A change made by the CRM is a normal Dolibarr link between the category and the third party (it fires the usual category triggers).
- **MCP.** `get_crm_opportunity` returns the `categories` (label and colour) of the account, `list_crm_opportunities` takes a `category_id`, and `update_crm_opportunity_categories` (right Create and update deals) replaces the categories of the account of a deal, with the same one-answer rule.
- **What is not there.** Categories belong to the **third party**, not to one deal (a second deal of the same account has the same tags); a category type for deals does not exist in Dolibarr.

## The public "not interested" link

**From v1.2.0.** Every e-mail the module sends for a deal ends with a link, **"Not interested, don't contact me again soon."** (in the language of the deal's third party when it has one, else the current language). It is added when the message is sent, whoever wrote the text: a salesperson in the editor, an automated outreach, or the AI assistant (the AI is told not to write such a line itself). It is also added to the text of "Open in my mail client" and "Copy text" of the editor, for a message sent from a mail program. There is no setting to leave it out. Owner reminders, which go to your own staff, do not carry it.

**What happens.** The link opens a public page, `custom/crm/optout.php`, that asks the prospect to press a button; the request is then recorded: the **deal is closed as Lost** with the loss reason **Not interested** (added to every pipeline's reasons by the schema step 5; rename it if you like, its code is `NOTINTERESTED`), a **done event** is added to the deal, the running outreach of the deal **stop** (reason "the prospect is not interested"), and the prospect sees a confirmation. The move is made in the name of the deal's owner, who appears in the history. Only an **open** deal is changed: the link of a deal already won or lost does nothing (the page still answers "already recorded"). The salesperson can reopen the deal by moving it to an open stage, as with any lost deal.

**The address.** The link uses the public address Dolibarr knows for itself (`$dolibarr_main_url_root`, with `$dolibarr_main_url_root_alt` for `custom`, in `conf.php`). **It must be an address your prospects can reach**: a link to `localhost` or to an intranet name is useless. The page must be reachable **without any login**: if a reverse proxy, a firewall or an HTTP authentication protects `/custom` or the whole instance, let `/custom/crm/optout.php` through. Test it from outside: `curl -i https://your-host/custom/crm/optout.php?h=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef` answers `404` with "This link is not valid" (a real link answers 200).

**Why it is safe to leave public.**
- The link carries **one** parameter, `h`: 64 lowercase hexadecimal characters, the HMAC-SHA256 of the entity and the deal number under a secret that is not in the database (the installation's unique id of `conf.php`). It cannot be guessed from a deal number. Any other parameter (in the query or in a POST body), another method or another shape of value gets the same neutral "not valid" page, and nothing is read from it.
- The database holds only the **SHA-256 of the token** (table `llx_crm_optout`), never the token: a copy of the database gives no working link. The token is found by an exact lookup of that hash; an unknown token tells nothing.
- The page shows **no data** of the deal or of the prospect, starts **no session**, sets **no cookie**, loads no script and no external file, and is sent with `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `X-Frame-Options: DENY` and a strict `Content-Security-Policy`.
- By default (`CRM_OPTOUT_CONFIRM` on) opening the link **changes nothing**: it shows a button, and only the POST of that button records the request. Mail security scanners and link previews open every link of a message; without this step they would close your deals by themselves. Turn it off only if you accept that.
- The link is the same in all the e-mails of a deal, and whoever holds it can use it, as with any unsubscribe link: do not publish a prospect's e-mail. A link can be used only on an **open** deal, once (the page then answers "already recorded").
- What an attacker can do with a link is limited to closing that one deal as Lost. There is no rate limit in the module: if you want one, set it in your web server or firewall for `optout.php`.

## Message history

**Attachments (from v1.2.0).** Files attached in the e-mail editor are sent by Dolibarr's mail class (`CMailFile`) and kept in `<documents>/crm/opportunity/<deal id>/mail/<file id>_<name>`, listed in the table `llx_crm_mail_file` (schema step 8), and shown under their message by the Message history (download through `mail_file.php`, with the CRM's right on the deal; a PDF opens inline from the magnifier). Limits: Dolibarr's `MAIN_UPLOAD_DOC` per file (2 MB unset), 10 files, 20 MB per e-mail (`CrmMailFile::MAX_*`), and the PHP limits. The folder follows the same permission rules as the notes files (see INSTALL.md), is part of the backup, and is deleted with its deal. Files of messages found in the archive mailbox are not stored. Check that your mail server accepts messages of that size.

A planned e-mail only marked as done is told apart by having **no e-mail subject** (`llx_actioncomm.email_subject`, which the module and the collector always fill when they record a message): it is shown as **Draft closed**, not as sent.

A line of an e-mail in the History of the deal, the Next step list, Activities or the CRM history of the Prospect tab links to the message here (`#crm-msg-<event id>`, opened by a small script of the card). MCP: `list_crm_opportunity_messages` returns the same messages with their text.

**From v1.2.0.** The deal card has a section **Message history**: every e-mail of the deal, oldest first, with its direction (sent or received), date, subject, addresses (from, to, Cc) and its **text**, which opens with **read** (or **show all**). It lists the messages sent by the platform (the editor, the automated outreach in send mode, the owner's steps), the **answers** of the prospect and the **copies** that the e-mail collector finds in the archive mailbox (see [E-mail history](#e-mail-history)). They are the done agenda events of the deal (`AC_EMAIL`, `AC_EMAIL_IN`), so they are also in the agenda of Dolibarr; the section reads them with the CRM's right on the deal, not the agenda's, and shows an HTML message as text. Attachments are not stored. Drafts and the messages that an outreach only simulated are not in the list (the outreach journal lists those).

## Deleting an automated outreach

**From v1.2.0.** Outreach are normally stopped or turned off, never deleted, so that the journal stays. For the cleaning of tests, the right **Configure CRM** (`setup`) allows a **complete deletion** (nobody else, not even with "Manage automated outreach"):

- **The whole outreach**: button *Delete the outreach completely* on its card; after a confirmation that counts the steps, enrolments (and how many are running) and journal lines, it deletes the journal lines, the enrolments, the steps and the outreach itself in one transaction. MCP tool `delete_crm_sequence`, **off by default**: it is not even listed to MCP clients until you switch on **Allow MCP tools that delete CRM records** (`CRM_MCP_ALLOW_DELETE`, CRM → Setup → Parameters; a confirmation is asked). This one switch is the gate of **every** MCP tool named `delete_*`: `delete_crm_opportunity`, `delete_crm_stage`, `delete_crm_activity`, `delete_crm_sequence`, `delete_crm_sequence_step` and `delete_crm_note_file`, and any delete tool added later. With the switch off (the default) none of them is listed and each refuses a direct call, whatever the rights of the user: an MCP client then can no longer delete anything in the CRM, **a change from earlier versions**, where `delete_crm_opportunity` and the others were always offered to a user holding the right. That setting is not exposed by `get_crm_settings` and there is no MCP tool to change it, so only a person in the setup screen can turn it on; turn it off after the cleaning. With it off, a call to the tool is refused too.
- **One enrolment**: the trash icon on its line of the enrolment list deletes it with its journal lines (clean a test on one deal).
- **What stays**: the deals, and every agenda event the outreach made on them (planned e-mail drafts, e-mails sent, tasks): events are never deleted by the module. Delete a leftover draft from the deal (Next step) if you need to. The action is logged (`CRM outreach <id> deleted completely by user <id>`).

## Internal notes and files

**From v1.2.0.** The deal card has internal notes instead of a description (see the user guide). What an administrator needs to know:

- Three tables hold them: `llx_crm_note` (text, author, date, last editor and date), `llx_crm_note_revision` (every earlier text of an edited note, with its author and date) and `llx_crm_note_file` (metadata of each attached file). The schema step 4 copies each existing description into a first note. The old column stays, unused.
- **Files are stored on disk**, in the documents directory of Dolibarr (`$dolibarr_main_data_root`): `<documents>/crm/opportunity/<deal id>/<file id>_<name>`. Disabling the module keeps them; deleting a deal deletes its files. A file that could run on the server (`.php`...) is stored with the extension `.noexe`.
- **Permissions of the folder.** The web server user must be able to write in it, and nobody else may read or write. As root, with the web server user of your system (`www-data`, `apache`, the PHP-FPM pool user...): `chown -R www-data:www-data <documents>/crm`, then directories `chmod 0750` and files `chmod 0640` (`find <documents>/crm -type d -exec chmod 0750 {} +` and `find <documents>/crm -type f -exec chmod 0640 {} +`). **Never `777` or `666`**: the files are customer documents. Keep the documents directory outside the web root (the files are only served by `note_file.php`, after a right check); never create the folder as another user (an administrator's shell, root, a command-line script): it would belong to that user and every upload would fail with a message about the documents directory; on SELinux, label it for the web server instead of disabling SELinux; back it up with the database, keeping owners and rights, and protect those backups like the database. The complete procedure is in [INSTALL.md](../INSTALL.md), "Folder for the files of the notes".
- **Agenda events (from v1.2.0).** Each note also makes a done event of type "Other", labelled "CRM note added" (language of the author), on the deal (`opportunity@crm`) and on its third party, in the name of the author; the link is `llx_crm_note.fk_action` (schema step 6, which also makes the events of the existing notes). Editing a note edits the event's text. Deleting a deal keeps the events, like every agenda event of the module. The deal's History and the account's CRM history show them with the start of the text; the Next step list and the rotting clock ignore them. No new event type is added.
- **Size limit**: Dolibarr's own setting for documents, `MAIN_UPLOAD_DOC` (in kB, 2048 when unset), also limited by PHP (`upload_max_filesize`, `post_max_size`). At most 10 files per note.
- **Downloads** go through `note_file.php`, never through a public link: the user needs the CRM read right and must be allowed to see the deal (own deals, or all with "Read every owner"). A PDF opens in the browser from the preview link; every other file is downloaded.
- Rights: **Create and update deals** to add a note and files and to edit; editing is limited to the author and to users with **Read every owner**. Reading needs **Read own deals**. There is no right to delete a single note.
- **MCP**: `list_crm_opportunity_notes`, `list_crm_opportunity_files`, `list_crm_note_versions` (read), `add_crm_opportunity_note`, `update_crm_note`, `add_crm_note_file`, `delete_crm_note_file` (write). Files are sent as base64 (`content_base64`) with a `filename`; reading a file's content is not offered, only its metadata. `get_crm_opportunity` returns `notes` instead of `description`, and `create_crm_opportunity` turns its `description` into the first note. `update_crm_opportunity` no longer takes a `description`.

## AI reply suggestions

In the e-mail editor of a planned e-mail (Write / send), an **AI assistant** block lets a salesperson ask the AI service of Dolibarr to read the exchange so far and propose the next e-mail. The module has no AI client of its own: it uses the provider, key, model and privacy settings of Dolibarr's **AI module**.

**What has to be in place**

1. The **AI** module is enabled (Home → Setup → Modules), and a text generation service is configured on its setup page (Home → Setup → AI: service, API key or URL, model). Test it there first. A service that needs a key and has none answers with an error, which the editor shows.
2. `CRM_AI_EMAIL_ASSIST` is set to 1 in CRM → Setup → Parameters (it is 0 by default, so nothing leaves until an administrator decides). The block shows for users who may write e-mail steps ("Create and complete next steps"); no separate AI right is needed.

**How it behaves**

- The salesperson sees a text area with the **default prompt**, which they can edit for this request, a **Suggest a reply with AI** button, and **Restore the default prompt**. The proposal replaces the text of the e-mail in the editor. **Nothing is saved or sent**: the person reads it, edits it, and uses the usual buttons. Reloading the page brings back the saved draft.
- A notice under the block names the service that will receive the text, for example "Custom (api.x.ai, grok-4.7)", and says whether names are masked.
- The prompt you set as default (`CRM_AI_EMAIL_PROMPT`) should say what you expect: tone, length, what never to promise, signature, language. The built-in one asks for a polite, concise, concrete reply in the language of the prospect's last message that answers every question, invents no price, date or commitment, and proposes a dated next step.
- Dolibarr's own AI settings still apply on top: a custom pre-prompt for "e-mail text generation" set in the AI module (Custom prompt tab) is added by the AI module to every call.

**Time limit.** Dolibarr stops an outgoing call after **30 seconds** (`MAIN_USE_RESPONSE_TIMEOUT`, and 5 seconds to connect, `MAIN_USE_CONNECT_TIMEOUT`). The test buttons of the AI setup send a short prompt and stay under it, but a full e-mail asked of a reasoning model can take longer, and the call then ends with "API request failed. No http received". The CRM therefore waits for the AI service up to **`CRM_AI_TIMEOUT`** seconds (Parameters tab, "AI time limit", default 120, kept between 10 and 600), for its own calls only: the limit is raised in memory for the duration of the call and put back afterwards, never below the Dolibarr one, and nothing global is changed. The web server must allow a request that long too: PHP-FPM `request_terminate_timeout`, nginx `fastcgi_read_timeout` or `proxy_read_timeout`, Apache `Timeout` or `ProxyTimeout` (the page raises the PHP `max_execution_time` itself when it is lower). When the call still fails without any HTTP answer, the message says how many seconds it took and whether the limit ran out (raise the setting) or the connection failed earlier (name not resolved, refused or reset, TLS, proxy, firewall). For the exact network error, set the Dolibarr log level to Info or lower and read the line `getURLContent response array` in `dolibarr.log`.

**What is sent to the AI service** (and nothing else)

- The prompt as written or edited by the salesperson.
- A summary of the deal: reference, title, account, contact name, stage and probability, amount, expected close date, owner's name, and the latest internal notes of the deal, up to three (from v1.2.0; the description before), cut at 1000 characters in all.
- The e-mails already recorded on the deal: the latest 10, oldest first, each marked "WE SENT" or "THE CUSTOMER WROTE" with date and subject, quoted lines (starting with `>`) removed and each cut to 1500 characters, 10 000 characters in total (the oldest are dropped first).
- The current text and subject of the draft, and a rule asking for the body of the e-mail only, in plain text.

Attachments and everything else in the agenda or the mailbox are never sent. The deal's e-mails reach the deal through the [e-mail history](#e-mail-history): without it, only e-mails sent from Dolibarr are there to read.

**Privacy.** The exchange contains personal data and may contain confidential information, and it goes to an external provider. Decide before you leave the block on:

- Check the provider's terms and where it processes data, and tell your users.
- Turn on **privacy redaction** (`AI_PRIVACY_REDACTION`). The CRM block then replaces names, e-mail addresses, phone numbers and references by placeholders before the call, and puts the real values back in the answer; the editor notice says it is on. The switch is hard to reach, because Dolibarr shows it only on the chat assistant page, itself hidden by default. The steps:

  1. Set the constant `MAIN_FEATURES_LEVEL` to 1 or more (Home → Setup → Other setup). This is temporary, and it also turns on every other experimental Dolibarr feature.
  2. Open the **Assistant** page of the AI setup (the "Assistant" tab that now appears, or directly `/ai/admin/assistant.php`).
  3. Switch **"Enable the chat AI assistant"** on (`AI_ASSISTANT_ENABLED` = 1). The other options of the page appear. The chat itself is only offered to users who hold the right "Use the AI Assistant", which nobody has by default (administrator accounts have every right).
  4. Switch **"Obfuscate Prompt Data"** on (`AI_PRIVACY_REDACTION` = 1).
  5. If you do not want the chat, switch **"Enable the chat AI assistant"** off again (`AI_ASSISTANT_ENABLED` = 0). This is your choice: `AI_PRIVACY_REDACTION` is an independent constant and **keeps its value of 1**, and the CRM module reads it directly, so the redaction stays in force for the CRM block.
  6. Put `MAIN_FEATURES_LEVEL` back to its previous value if you do not need the experimental features.

  Open the e-mail editor of a planned e-mail to check: the notice under the AI block should say that names, addresses and references are masked. Dolibarr itself applies this setting only to its chat assistant, its e-mail cleaner and to attachments; the CRM block applies it because the module does it on its own, but Dolibarr's other text generation features do not. With it on, check that the proposals read well on your provider.
- Or leave `CRM_AI_EMAIL_ASSIST` at 0, its default, so the block stays hidden.

**Limits.** The proposal is only as good as the model and the history it can read; it can be wrong, so every text is read and edited by a person before sending. Each use costs a call to the provider. A very short or empty history gives a generic proposal.

## MCP

The module adds 49 tools to Dolibarr's MCP server, named verb first with `crm` after the verb (`list_crm_opportunities`, `create_crm_activity`), so an AI assistant can read and act on deals within the user's rights. Screens work whether or not the AI module is on.

1. Enable the **AI** module (Home → Setup → Modules).
2. The AI setup tabs "MCP server" and "Tool access control" show only when the constant `MAIN_FEATURES_LEVEL` is 2 or more on Dolibarr 24.0.0, 1 or more on 25 (Home → Setup → Other setup). Without it, `ai/admin/server_mcp.php` and `ai/admin/configure_tools.php` can still be opened directly. `MAIN_FEATURES_LEVEL` also turns on every other experimental Dolibarr feature.
3. On the MCP server tab, switch the server on (`AI_MCP_ENABLED`). Then either generate the **shared MCP key** and choose the user the tools run as (`AI_MCP_USER_ID`), or use a **personal Dolibarr API key** for each user.
4. A user who connects with a personal API key needs the AI right "use the assistant". With the shared key, the tools run as the chosen user. Either way the tools list only what that user's CRM rights allow. Use a dedicated user with exactly the rights the assistant needs.
5. Tool access control (`ai/admin/configure_tools.php`, setting `AI_MCP_SERVER_ALLOWED_TOOLS`): while it is empty (the page was never used), every tool is allowed. Once it holds a list, the CRM tools must be in it, each by name, and a tool added by an update of the module (for example `update_crm_activity` and `delete_crm_activity`) is refused until you tick it. **The page labels a tool "Modify" when its name starts with `create`, `update`, `delete`, `add`, `remove`, `change`, `write`, `edit`, `validate`, `pay` or `send`, and "View only" otherwise**, and its "View only" preset allows every "View only" tool and replaces the whole list. The CRM tools are named for that rule (read tools `list_crm_*` and `get_crm_*`, the others start with one of those verbs), so the labels are right and the preset leaves the CRM tools that change data out. Tick them yourself, in steps. That page shows every tool whatever the list and the user's rights, so it is also the place to check that the module's tools are registered at all.
6. A client calls `POST /ai/server/mcp_server.php` with `Authorization: Bearer <key>`: `initialize`, then `tools/list`, then `tools/call`.

Keys are secrets: never store them in the project, and regenerate one that was shared.

**Version differences.** The CRM tools work on Dolibarr 24.0.0 and on 25, but the core around them is not the same:

- **24.0.0** has the MCP server, but it authenticates with the **shared key only** (`AI_MCP_API_KEY`, running as the user chosen in `AI_MCP_USER_ID`), does not check the rights a tool declares, and does not ask for a confirmation before a write. To keep the rights of the CRM, the tools check their own rights before running, so a caller who lacks, for example, "Delete deals" or "Configure CRM" is refused. The confirmation step before a write is not there: use a dedicated MCP user with only the rights the assistant needs. The AI setup tabs need `MAIN_FEATURES_LEVEL` at 2 or more.
- **25.0.0 alpha** adds personal API keys for MCP, the per-tool rights check and the confirmation step before a write, and shows the AI tabs from level 1. Both sets of rights checks apply: the core's and the tool's own.

The tools cover pipelines, stages, sources, loss reasons and qualification items (list and change), deals (list, read, create, update, move, win, lose, delete), proposals links, qualification answers, agenda steps (list, create, complete, change the date or title of a planned step, delete a planned step), outreach, reports, reading the settings and running the jobs (`get_crm_settings`, `send_crm_due_sequence_steps`, `send_crm_owner_reminders`). Tools that write return a confirmation text that names the record. The tools that change setup need "Configure CRM"; the others follow the matching right. Owners are numeric Dolibarr user ids: there is no user search tool. Searching and editing third parties, contacts, proposals, orders and invoices stays with the core tools.

The 49 tools by the CRM right they need (a user without it does not see the tool on Dolibarr 25 and is refused by the tool itself on 24.0.0):

| Right | Tools |
| --- | --- |
| Read own deals (`read`) | `list_crm_opportunities`, `list_crm_opportunity_messages`, `get_crm_opportunity`, `list_crm_opportunity_notes`, `list_crm_opportunity_files`, `list_crm_note_versions`, `list_crm_activities`, `list_crm_sequences`, `get_crm_sequence`, `list_crm_pipelines`, `list_crm_stages`, `list_crm_sources`, `list_crm_loss_reasons`, `list_crm_qualifications`, `list_crm_stage_history` |
| Read CRM reports (`report`) | `get_crm_dashboard`, `get_crm_forecast`, `get_crm_conversion`, `get_crm_win_rate`, `get_crm_cycle_time`, `get_crm_loss_reasons`, `get_crm_by_owner`, `get_crm_by_source` |
| Create and update deals (`write`) | `update_crm_opportunity_categories`, `add_crm_opportunity_note`, `update_crm_note`, `add_crm_note_file`, `delete_crm_note_file`, `create_crm_opportunity`, `update_crm_opportunity`, `change_crm_opportunity_stage`, `change_crm_opportunity_to_won`, `change_crm_opportunity_to_lost`, `add_crm_proposal_link`, `remove_crm_proposal_link`, `update_crm_qualification_answers` |
| Delete deals (`delete`) | `delete_crm_opportunity` |
| Create and complete next steps (`activity`) | `create_crm_activity`, `change_crm_activity_to_done`, `update_crm_activity`, `delete_crm_activity` |
| Manage automated outreach (`sequence`) | `create_crm_sequence`, `update_crm_sequence`, `add_crm_sequence_step`, `update_crm_sequence_step`, `delete_crm_sequence_step`, `add_crm_sequence_enrolment`, `change_crm_sequence_enrolment_to_stopped`, `update_crm_opportunity_replied`, `send_crm_due_sequence_steps` |
| Configure CRM (`setup`) | `delete_crm_sequence`, `get_crm_settings`, `send_crm_owner_reminders`, `create_or_update_crm_pipeline`, `create_or_update_crm_stage`, `delete_crm_stage`, `create_or_update_crm_source`, `create_or_update_crm_loss_reason`, `create_or_update_crm_qualification` |

`send_crm_due_sequence_steps` and `send_crm_owner_reminders` run the scheduled jobs: they send real e-mail unless the dry-run settings (`CRM_DRY_RUN`, `CRM_REMINDER_DRY_RUN`) are on. `delete_crm_opportunity` deletes the deal for good. Allow the tools that change data in steps, not all at once, and only for a user whose rights you chose on purpose.

## Maintenance

**After an update of the module.** The procedure is in [UPDATE.md](../UPDATE.md): back up, replace the files (they are live at once), then **disable and enable the module** in Home → Setup → Modules. That re-enable runs the database steps and registers again the rights, menus, hooks, scheduled jobs and home box a version can change. It is safe: tables, deals, settings and agenda events are kept, and nothing is seeded twice.

**Database schema version.** Dolibarr keeps no version for an external module, so the module keeps its own: a whole number, the schema version, stored in the setting `CRM_SCHEMA_VERSION`, against the number the code expects. CRM → Setup → **About** shows the module version and the schema "installed / expected". When the code is ahead of the database (files updated, module not re-enabled yet), a notice on CRM → Home and CRM → Setup, shown to people who hold "Configure CRM", asks for the re-enable; until then the module works without the new feature. A step that fails stops the enable with its reason and is retried at the next enable.

**Disabling the module** removes the menus, rights, home box and the two scheduled jobs. It does **not** drop the `llx_crm_*` tables, does not delete agenda events (calls, meetings, e-mails stay in the agenda, but no longer link back to the deal while the module is off), and does not touch third parties, contacts, proposals or users. Enabling it again finds everything.

**Removing the data for good.** There is no purge button. To erase the CRM data, drop the `llx_crm_*` tables and delete the `CRM_*` constants yourself, after a backup. Agenda events with `elementtype = 'opportunity@crm'` stay unless you delete them.

**Backups.** Back up the Dolibarr database: the module stores nothing on disk. Mail credentials of the collector are in the database too.

**Watching it work**

- Home → Setup → Scheduled jobs: last run and result of the CRM jobs and of the e-mail collector.
- CRM → Automated outreach → an outreach → Journal: every e-mail attempt and its result, with a link to the e-mail itself.
- The Dolibarr log (`documents/dolibarr.log`) records the module's e-mail archive decisions (`CRM e-mail archive: recorded|duplicate|ignored|error`).
- The e-mail collector card shows what each collection did.

**Languages.** English and French are maintained by hand; every other language of Dolibarr has a machine translation (`langs/*/crm.lang`) that has not been reviewed by a native speaker. To correct a wording, edit the key in that file; Dolibarr falls back to English for a missing key. Entries you create are shown as typed, in every language.

**Agenda event types.** E-mail events use the types "Send Email" and "Reception Email" of Dolibarr's agenda (`AC_EMAIL`, `AC_EMAIL_IN`) whether or not an administrator has activated them in Home → Setup → Dictionaries → Types of agenda events; calls use "Phone call" and meetings "Rendez-vous", which must be active, otherwise the event is recorded as "Other". The module never adds event types of its own.

## Settings reference

| Constant | Default | Meaning |
| --- | --- | --- |
| `CRM_ROTTING_DAYS` | 15 | Rotting days offered when adding a stage |
| `CRM_FORECAST_MONTHS` | 3 | Months covered by the forecast |
| `CRM_MAX_EMAILS_PER_RUN` | 50 | Cap of sent or simulated e-mails per job run |
| `CRM_MAIL_FROM` | empty | Sender; empty falls back to the Dolibarr sender |
| `CRM_SEQUENCES_ON` | 0 | Outreach job on or off |
| `CRM_SEQUENCE_EMAIL_MODE` | `draft` | `draft` or `send` |
| `CRM_DRY_RUN` | 1 | Send mode: journal only |
| `CRM_REMINDERS_ON` | 1 | Owner reminders on or off |
| `CRM_REMINDER_DRY_RUN` | 1 | Reminders: journal only |
| `CRM_REQUIRE_NEXT_STEP` | 1 | Refuse a stage change without a future next step |
| `CRM_REPLY_MOVES_DEAL` | 1 | Move an open deal to the reply stage of its pipeline when the prospect replies |
| `CRM_AI_EMAIL_ASSIST` | 0 | Show the AI reply suggestion block in the e-mail editor (needs the AI module) |
| `CRM_AI_TIMEOUT` | 120 | Seconds the CRM waits for the AI service (e-mail editor and step text button), 10 to 600. Dolibarr's own limit is 30 seconds, raised for these calls only |
| `CRM_AI_MAX_PER_HOUR` | 30 | AI suggestions one user can ask per hour (1 to 1000) |
| `CRM_AI_EMAIL_PROMPT` | empty | Default AI prompt; empty uses the built-in one (4000 characters at most) |
| `CRM_ARCHIVE_MAILBOX` | empty | Archive mailbox address; empty means no copy |
| `CRM_ARCHIVE_COPY` | `bcc` | `bcc` or `cc` |
| `CRM_ARCHIVE_REQUIRE_AUTH` | 0 | Keep a collected message only if its server reports a passed SPF/DKIM/DMARC check |
| `CRM_OPTOUT_CONFIRM` | 1 | From v1.2.0. The public "not interested" page asks for a confirmation before it records the request |
| `CRM_INTERNAL_REF_URL` | empty | From v1.2.0. Base address that prefixes the internal reference of a deal to make it a link; http or https only |
| `CRM_CATEGORY_SINGLE` | empty | From v1.2.0. Ids of the categories of third parties whose sub-categories exclude each other (a degree of interest), set in CRM → Setup → Parameters |
| `CRM_DEFAULT_TIMEZONE` | empty | From v1.2.0. Time zone name (e.g. `Europe/Brussels`) used for a session whose browser gave no real zone (none or UTC) |
| `CRM_MCP_ALLOW_DELETE` | 0 | From v1.2.0. Offers every `delete_*` MCP tool; not exposed by `get_crm_settings` |
| `CRM_OPTOUT_SECRET` | created if needed | From v1.2.0. Secret of the "not interested" links, created only when `conf.php` has no `$dolibarr_main_instance_unique_id` (which is used first). Never change or delete it: every link already sent would stop working |
| `SEARCHFORM_POSITION_CRM` | 42 | Position of "CRM opportunities" in the general search box (optional, set in Other setup) |
| `MAIN_MODULE_CRM_ICON` | `fa-handshake` | Top menu icon; the theme reads it. Set at enable |

## Security

What the module does, and what stays with you.

- **Rights.** Every page and every MCP tool checks the CRM rights; deals, enrolments, journal and e-mails of an outreach follow the owner rule ("Read" = own deals, "Read all" = every deal). Give "Setup" and "Delete" only to administrators, and do **not** give them to the user an AI assistant connects as: an MCP caller can then delete deals, run the outreach job and send the owner reminders. Settings cannot be changed through MCP: the tool was removed, only `get_crm_settings` reads them. Use a dedicated user with the rights the assistant needs.
- **Forms and links.** Every action carries the session token (CSRF). Dolibarr's own level `MAIN_SECURITY_CSRF_WITH_TOKEN` is not lowered by the module; keep it at 2 or 3.
- **E-mail.** Subjects, recipients and senders are made single-line before sending. The sender and the archive address are validated when saved. In SMTP mode the Dolibarr mail settings decide the transport; use an authenticated connection.
- **Inbound e-mail.** See [E-mail history](#e-mail-history): only mail tied to a known address of the deal is kept.
- **AI.** Redaction (`AI_PRIVACY_REDACTION`) masks the names of the deal, the contact and the owner, and what Dolibarr's privacy guard recognises (addresses, phone numbers, references); it does not mask free text such as notes or the body of the e-mails. Customer text is data for the model, which is told not to follow instructions found in it; the answer is never saved or sent without a person. A per-user hourly limit (`CRM_AI_MAX_PER_HOUR`) bounds the cost.
- **Errors.** Database and provider errors go to the Dolibarr log (`dolibarr.log`); only an administrator sees them on screen.
- **Files.** Do not expose `.git`, `doc`, `test`, `sql`, `AGENTS.md` and the other source files on a production server; see INSTALL.md ("Keep the repository files private"). Set `$dolibarr_main_prod='1'` in production so PHP errors are not shown.
- **Personal data.** The journal of an outreach keeps the text of each e-mail, and the e-mail history keeps the messages as agenda events; both are personal data. Decide how long to keep them and purge them (delete the deal, or the events) when the delay is over. The HTML of an e-mail may contain remote images: opening a journal entry loads them, which tells their server that the page was opened.
- **Audit.** Changes of settings are written to the Dolibarr log with the user id and the setting name, never the value.

## Troubleshooting

| Symptom | Likely cause | What to do |
| --- | --- | --- |
| The module cannot be enabled | Third parties or Agenda is off, PHP below 8.1, Dolibarr below 21 | Enable the dependencies, check the versions |
| No **CRM** menu for a user | No CRM right | Grant "Read own deals" or "Read every owner" |
| A user does not see a colleague's deals | No "Read every owner" | Grant it, or the deal owner changes |
| Outreach, reminders or the collector never run | No system cron entry, or the job is disabled | Add the cron entry, check Scheduled jobs |
| An outreach runs but sends nothing | Draft mode (the default), or dry-run on, or the master switch off | Read the settings above; look at the journal |
| Nothing is actually sent | Dolibarr's e-mail setup is empty or wrong | Home → Setup → Emails, send a test message |
| Reminder or outreach e-mail "no address" | The owner or the deal contact has no e-mail | Fill it in; the company address is never a fallback |
| The collector offers no "CRM: record the e-mail" operation | The module was not re-enabled after the update | Disable and enable CRM once |
| E-mails reach the mailbox but nothing appears on the deal | The message cannot be tied to a deal (new thread, no reference in the subject) | Have it sent from a draft, or from a reply in the thread, with the reference kept |
| The same e-mail appears twice | Rare: a message registered by hand and a real one with a very different subject | Delete the entry registered by hand |
| The forecast is empty | No open deal with an amount and an expected close inside the window; won and lost deals are never forecast | Check the deals and `CRM_FORECAST_MONTHS` |
| The top menu shows a generic icon | `MAIN_MODULE_CRM_ICON` missing | Set it to `fa-handshake` and reload with a cleared cache |
| The AI block is missing from the e-mail editor | The AI module is off, `CRM_AI_EMAIL_ASSIST` is 0 (the default), or the user cannot write e-mail steps | Enable the module, set the constant to 1, check the user's rights |
| "The AI service did not answer" | The AI module has no key, URL or model, or the provider refused the call | Fix it in Home → Setup → AI and test there; the message gives the provider's reason |
| "The AI service did not answer: no answer after N seconds, the time limit … ran out" | The AI service is slower than `CRM_AI_TIMEOUT`, or the web server cuts the request earlier | Raise "AI time limit" and the web server time limits (see "Time limit" in the AI section) |
| "… no HTTP answer after N seconds, well before the limit" | The connection failed: name not resolved, refused or reset, TLS error, proxy or firewall | Read `getURLContent response array` in `dolibarr.log` (log level Info or lower) |
| The MCP tabs are missing | `MAIN_FEATURES_LEVEL` is 0 | Set it to 1, or open `ai/admin/server_mcp.php` directly |
| An MCP client gets "MCP Server Disabled" | The AI module or `AI_MCP_ENABLED` is off | Enable them |
| An MCP tool is not listed | The user lacks the right, or the tool is not in the allowed list | Check the user's CRM rights and the tool access control |
| No CRM tool at all, not even in the unfiltered list of `ai/admin/configure_tools.php` | The hook does not return them: an old copy of the module (before the tools were returned the way Dolibarr 24.0.0 requires), PHP serving cached files (opcache), or an error in the hook, which the core only writes to the log (`[McpHandler] Error during 'addMcpTools'`) | Deploy the current module files and reload PHP, check `MAIN_MODULE_CRM_HOOKS` contains `aimcp`, read the log. `configure_tools.php` ignores the allowed list and the rights, so a tool missing there is not a filtering problem |
