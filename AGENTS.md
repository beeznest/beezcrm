# AGENTS.md

Guidance for AI agents working on the **CRM** external module for Dolibarr. Read this first, then `README.md` (overview and links), `doc/ADMIN_GUIDE.md` (install, Dolibarr settings, every setup page, cron, e-mail, MCP, maintenance), `doc/USER_GUIDE.md` (what a salesperson does) and `doc/STRUCTURE_ANALYSIS.md` (design decisions, data model, rationale).

## Environment

| Item | Value |
| --- | --- |
| This module (real path) | `/var/www/dolibarr-crm` — the repo root *is* the module root (flat layout, no nested `htdocs/`) |
| Dolibarr install | `/var/www/dolibarr` (git checkout of upstream `develop`; code in `htdocs/`) |
| Dolibarr version | 25.0.0-alpha (`htdocs/version.inc.php`). Module requires Dolibarr >= 21, PHP >= 8.1 |
| Local PHP | 8.3 CLI. The system `phpunit` needs PHP 8.4, so it does not run here |
| Runtime link | `/var/www/dolibarr/htdocs/custom/crm` -> `/var/www/dolibarr-crm` |
| Instance URL | http://my.dolibarr.net — module pages live under `/custom/crm/` (e.g. `/custom/crm/index.php`) |
| Admin login | `admin` / `adminadmin123` (dev instance only; do not copy into code, docs or commits) |
| DB | MariaDB/MySQL, database `dolibarr`, prefix `llx_`, entity 1. Credentials are in `/var/www/dolibarr/htdocs/conf/conf.php` (do not echo them). `root` without password is refused |
| Module state | Enabled on the instance (CRM top menu shows). `doc/STRUCTURE_ANALYSIS.md` still says "not enabled": that line is stale |

`conf.php` sets `$dolibarr_main_document_root_alt='/var/www/dolibarr/htdocs/custom'` and `$dolibarr_main_url_root_alt='/custom'`, which is why the module is served from `/custom/crm/`. `$dolibarr_main_prod='0'` (dev mode, errors visible).

Editing files in `/var/www/dolibarr-crm` is live immediately through the symlink. There is no build step. This directory is not a git repository.

Do not edit anything under `/var/www/dolibarr/htdocs` (core). The design rule is: no core patch, no extrafield, no `ALTER` on core tables, no new `llx_c_actioncomm` rows. If a change seems to need one, find another way or ask.

## Dolibarr in a nutshell

- Entry for every page: `main.inc.php` (session, auth, `$db`, `$user`, `$conf`, `$langs`, `$hookmanager`). Core classes are `CommonObject`, `CommonObjectLine`, `DoliDB`, `Form`, `FormCompany`, `ActionComm`, `Societe`, `Contact`, `Propal`, `User`.
- Core directories under `htdocs/`: `core/` (modules, lib, tpl, boxes, triggers), `societe/` (third parties, contacts), `comm/` (propal, agenda in `comm/action`), `projet/`, `ai/` (AI + MCP server), `langs/`, `install/`, `admin/`.
- Modules are classes `modXxx extends DolibarrModules` that declare rights, menus, boxes, cron jobs, hooks, constants and dictionaries. Enabling a module runs `init()` (loads `sql/*.sql`, registers everything); disabling runs `remove()`. Enabled state is the constant `MAIN_MODULE_<NAME>`; test with `isModEnabled('crm')`.
- Permissions: `$user->hasRight('crm', 'read')`. Rights ids are declared in the descriptor and stored in `llx_rights_def` / `llx_user_rights` at enable time.
- Tables are named `llx_*` in SQL files; Dolibarr substitutes the real prefix. Most tables carry `entity` (multicompany); always filter with `entity IN (getEntity('...'))` or `$conf->entity`.
- Strings go through language files `langs/<locale>/<file>.lang`, loaded with `$langs->load('crm@crm')` and used as `$langs->trans('Key')`. Dolibarr caches lang files, so reload the page after edits.
- Hooks: a class `ActionsXxx` named after the module is called for each hook context a module lists in `module_parts['hooks']`. The CRM module uses the context `aimcp`.
- Security conventions: CSRF `token` on every POST (`newToken()` / `checkToken`), `GETPOST('x', 'alpha|int|...')` for all input (never `$_GET`/`$_POST`), `$db->escape()` / `(int)` casts in SQL, `dol_escape_htmltag()` on output, `accessforbidden()` for denied access.
- Cron: `llx_cronjob`, executed by `scripts/cron/cron_run_jobs.php` or the web "scheduled jobs" page. Jobs are declared in the descriptor `$this->cronjobs`.
- AI/MCP: `htdocs/ai/` provides an MCP server. `McpHandler` collects tools and asks modules for more through the `addMcpTools` hook in context `aimcp`. Tools extend `McpTool` (`ai/class/mcptool.class.php`) and implement `getDefinitions()` and `execute()`.
- Core references worth reading before changing behaviour: `htdocs/core/modules/DolibarrModules.class.php`, `htdocs/ai/class/mcptool.class.php`, `htdocs/ai/tools/crud_objects.class.php`, `htdocs/comm/action/class/actioncomm.class.php`, `dev/skeletons/` and `htdocs/modulebuilder/` for idioms.

## What this module does

A sales pipeline: account (`llx_societe`), contact (`llx_socpeople`), **deal** (`llx_crm_opportunity`), proposals (`llx_propal`, many per deal), and agenda events (`llx_actioncomm`) as next steps and history. Nothing from core is copied into private tables. Module technical name `crm`, module number `104801`, rights `10480101`–`10480108`, tables `llx_crm_*`, constants `CRM_*`, MCP tools `crm_*`.

Key rules (details in `doc/STRUCTURE_ANALYSIS.md`):

- Probability belongs to the **stage**, not the deal. Moving a deal copies the stage's meaning; there is no per-deal percentage column.
- Pipelines have stages with probability, colour, rotting delay, forecast category (`pipeline` / `upside` / `commit`) and won/lost flags.
- Open deals need a future agenda event (`CRM_REQUIRE_NEXT_STEP`, default on). A stage move between open stages is refused without one. Won/lost do not need it.
- Closing lost requires a loss reason from the dictionary. Both won and lost write `date_close` and a stage-history row.
- Reports use `llx_crm_stage_history` (cohort conversion), not column counts. Win rate = won / (won + lost) by close date; cycle time uses won deals only.
- Qualification is a short editable checklist (seed NEED / BUDGET / DECISION / TIMELINE, 25 points each); score is the sum of "yes" points, capped at 100.
- Agenda events link to a deal with `elementtype = 'opportunity@crm'` and `fk_element = <deal id>`, and use existing types `AC_TEL`, `AC_RDV`, `AC_OTH` (`AC_EMAIL` and `AC_EMAIL_IN` for e-mails, active or not, as core does). They are created through `ActionComm`, never by direct SQL, and survive module disable.
- E-mail history: customer e-mails sent for a deal go through `crmSendMail(..., $dealId)` (tracking id `crm<dealId>` in the Message-ID, optional copy to `CRM_ARCHIVE_MAILBOX`) and are stored as done agenda events. Reading the mailbox is the core E-mail collector's job; the module only adds the operation `hookcrmrecordemail` (`ActionsCrm`, class `CrmMailArchive`). Never add IMAP code to the module. Details in `doc/STRUCTURE_ANALYSIS.md` ("E-mail history").
- Sequence e-mail steps are drafts by default (`CRM_SEQUENCE_EMAIL_MODE`; anything but `send` means draft): a planned `AC_EMAIL` event, label = subject with `[<deal ref>]`, note = text, reviewed in `email_compose.php` through `CrmEmailDraft`. Sending, registering a manual send and discarding replace or delete the event (`ActionComm::update` does not write the e-mail columns, so no in-place update and no SQL). `CrmMailArchive` matches an imported message to a deal by tracking id, thread, or the `[<ref>]` in the subject, and replaces a pending outgoing event instead of doubling it.
- E-mail is plain text or HTML, one form only: the plain-text alternative of an HTML message is derived (`crmHtmlToText`), never stored or edited apart, because Dolibarr's `CMailFile` builds it and cannot take another. Anything that reads an event note as text must convert it with `crmHtmlToText()` when `crmLooksLikeHtml()`. Read HTML input with `restricthtml`, plain input with `nohtml`. The ajax `previewtext` action must define `NOTOKENRENEWAL` before the bootstrap.
- AI reply suggestions go through `CrmAiReply` and Dolibarr's `Ai` class; the module never talks to a provider itself. It must honour `AI_PRIVACY_REDACTION` (mask, then unmask the answer), must never save or send what the AI returns, and must list in `doc/ADMIN_GUIDE.md` exactly what is sent. Tests inject a generator callable instead of calling a provider.
- Core compatibility: the module is run against the Dolibarr 24.0.0 core and 25 (alpha). Do not call a core method added after 24.0.0 without `method_exists()` or a local fallback (`PrivacyGuard::maskNames()` and the `McpTool` rights and confirmation API are 25 only). Every MCP tool must check its own declared rights (`denyMissingRights()` at the top of `execute()`), because 24.0.0 does not. To test against another core, extract it with `git archive <commit> htdocs` into a scratch directory with its own `conf.php` pointing at a copy of the settings, and run the CLI scripts inside transactions.
- Schema: `sql/llx_crm.sql` creates the tables at the first enable only. A column added later must also be added to `crmEnsureSchema()` (called from `modCrm::init()`, idempotent), and the code must still work when the column is missing (`crmStepModeSupported()`), because an installation is only migrated when the module is re-enabled. `DDLAddField` skips an empty `DEFAULT`, so write such an `ALTER` by hand.
- Two hourly cron jobs: owner reminders (on, dry-run on by default) and customer sequences (off, dry-run on by default). Mail goes to the deal contact, never to the company address.
- Visibility: without `readall`, users see only deals where `fk_user_resp` is themselves (`crmOwnerSql()`, `crmCanReadOwner()`). This applies to UI, cron and MCP alike.
- Disable never drops `llx_crm_*` and never deletes events. Seeds run only when the entity has no pipeline.

## Layout

```text
core/modules/modCrm.class.php   Descriptor: rights, menus, cron jobs, box, hooks, init()/remove()
core/boxes/crmwidget.php        Home box (overdue steps, deals without next step)
class/                          Domain classes: Opportunity (extends CommonObject), Pipeline, Stage,
                                Qualification, Sequence, CrmActivity, CrmReport, CrmCron, ActionsCrm (hook)
class/mcp/                      MCP tools: CrmMcpTool (base) + Dictionary / Opportunity / Activity /
                                Sequence / Report tools. Registered in class/actions_crm.class.php
lib/crm_bootstrap.inc.php       First include of every page: locates and loads main.inc.php
lib/crm.lib.php                 Helpers needing Dolibarr (rights SQL, mail, constants, seed, dictionaries, refs)
lib/crmlogic.lib.php            Pure rules, no Dolibarr (placeholders, score, due date, rotting, weighted)
index.php pipeline.php opportunity_{list,card}.php activity_list.php sequence_{list,card}.php report.php
admin/setup.php                 Settings, pipelines/stages, qualification, dictionaries
js/pipeline.js css/crm.css      Kanban drag and drop (posts with CSRF token), styles; no third-party script
sql/llx_crm.sql                 Single schema file, indexes inline, no IF NOT EXISTS, no .key.sql
langs/{en_US,fr_FR}/crm.lang    Only these two languages
test/phpunit/CrmLogicTest.php   Tests for the pure logic lib
```

Pages start with `require_once __DIR__.'/lib/crm_bootstrap.inc.php'` (needed because the real path is outside `htdocs`, so a relative include of `main.inc.php` fails) and then call `crmStartPage()`, which loads langs, refuses a disabled module and checks the read right.

## Conventions

- `declare(strict_types=1)` in every PHP file. PHP 8.1 compatible syntax only.
- **Tabs** for indentation. Dolibarr style: Allman braces for classes and methods, `if (...) {` on the same line otherwise, `array(...)` rather than `[]` is the dominant style here; match the surrounding file.
- Keep actions and view apart in pages. Business logic (stage moves, closing, qualification, sending, reminders) lives in the classes so pages, cron and MCP tools call the same methods. Do not duplicate rules in a page or a tool.
- Put rules that need no Dolibarr in `lib/crmlogic.lib.php` so they stay unit-testable.
- SQL: use `MAIN_DB_PREFIX`, cast ints, `$db->escape()` strings, filter by `entity`. Schema changes go in `sql/llx_crm.sql` only; there is no migration mechanism yet, so a column added to an existing table needs a manual `ALTER` on the dev DB and a note to the user (tables are created only at first enable).
- Seeded entries (pipeline, stages, sources, loss reasons, qualification items) are stored in English and translated at display time by `crmDictLabel()`, which matches the seed's English label, so a renamed or custom entry stays as stored. Show every such label through it, and when you add a seed entry add its key to the map in `lib/crm.lib.php` and to both lang files. MCP tools return the stored label.
- The on/off settings of `admin/setup.php` are switches handled by the page itself (action `toggle` by ajax, `set_<CONST>`/`del_<CONST>` as links), not by Dolibarr's `constantonoff.php` ajax, which is for administrators only while the Setup screen needs only "Configure CRM". A new boolean setting goes in the `$switches` list; it is stored as 1 or 0, never deleted, because `crmEnsureConstants()` recreates a deleted constant with its default.
- Output gotchas: `dol_escape_htmltag($text)` turns newlines into a literal `\n`; for text areas and multi-line text use `dol_escape_htmltag($text, 0, 1)`. Plain-text input (an e-mail body, a subject) is read with `GETPOST(..., 'nohtml')`: `restricthtml` turns `&` into `&amp;` and `alphanohtml` drops quotes.
- Every user-visible string goes in **both** `langs/en_US/crm.lang` and `langs/fr_FR/crm.lang`. Key style: `CrmSomething`.
- Do not add third-party JS/CSS libraries. Do not add a REST API, a private activity table, a scoring-rule engine, or a custom app shell (see "Out of scope" in `doc/STRUCTURE_ANALYSIS.md`).
- MCP tools: read tools return `NO_WRITE`; write tools return `writeConfirmationPreview()` text naming the record; `getRequiredRights()` must match the permission table; owners are numeric user ids (there is no user search tool). Add a new tool class to `ActionsCrm::addMcpTools()` and to the tool table in the docs.
- Adding a right means a new entry in the descriptor with the next id (`10480109`...). Existing instances only pick it up after the module is disabled and re-enabled.
- The general search box gets a CRM entry from the hook `addSearchEntry` (context `searchform`, `ActionsCrm`), which links to `opportunity_list.php?status=all&search_all=<text>`. Dolibarr lists core modules itself and external modules only through that hook.
- Hook contexts are declared in `module_parts['hooks']` and stored in the constant `MAIN_MODULE_CRM_HOOKS`; a new context needs a re-enable (or an update of that constant). Register only the context a method needs: the collector calls `doCollectImapOneCollector` for both `emailcolector` and `emailcollector`, and CRM registers only the second, so a message is not processed twice.
- Descriptor changes (menus, cron jobs, rights, box) apply only on re-enable: Home → Setup → Modules → CRM (off then on), or via the DB/CLI. Re-enable is safe: tables, deals, constants and events are preserved.

## Verifying changes

1. Syntax: `php -l path/to/file.php` for every touched PHP file (PHP 8.3 locally).
2. Pure logic tests: `test/phpunit/CrmLogicTest.php` does not load Dolibarr. The installed `phpunit` requires PHP 8.4 and fails here; run the assertions with another PHPUnit (e.g. a phar compatible with PHP 8.3) or a throw-away script that requires `lib/crmlogic.lib.php`.
3. In the browser or with curl against http://my.dolibarr.net: log in as `admin` (the login form needs the `token` hidden field from `index.php`; send `actionlogin=login&loginfunction=loginfunction`), then open `/custom/crm/...`. Watch `/var/www/dolibarr/documents/dolibarr.log` and the PHP error log for warnings, since dev mode shows them.
4. For the kanban or other JS-heavy pages, drive a real browser; curl cannot check drag and drop.
5. For cron and sequences, keep `CRM_DRY_RUN` and `CRM_REMINDER_DRY_RUN` on while testing. Never switch dry-run off on this instance without telling the user: it sends real e-mail to deal contacts.
6. For MCP: the AI module must be enabled, the tool must pass `AI_MCP_SERVER_ALLOWED_TOOLS`, and the user needs the matching `crm` right (and `Read own deals` at least).

Test data created on the dev instance (deals, sequences, agenda events) is real data in a shared DB: clean up what you create, and do not touch core objects you did not create.

## Known gaps and traps

- `doc/STRUCTURE_ANALYSIS.md` is the design record. When behaviour changes, update it in the same change, together with `doc/ADMIN_GUIDE.md` (anything an administrator sets, installs or checks), `doc/USER_GUIDE.md` (anything a salesperson sees or does) and this file if setup facts move. `README.md` stays a short overview: do not grow it again.
- No schema migrations and no purge action (explicitly deferred in the design doc).
- Multi-currency is not supported; amounts are in the company currency.
- Reply detection is manual ("prospect replied" on the card or by MCP); the module never reads a mailbox.
- Languages beyond English and French are out of scope.
- The tests directory is not part of the Dolibarr core suite and has no bootstrap; keep tests free of Dolibarr dependencies unless you add one.
