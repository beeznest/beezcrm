# Installing the CRM module

For an administrator who installs the module on a Dolibarr instance for the first time. To update an installation that already exists, read [UPDATE.md](UPDATE.md). Everything you can configure afterwards is in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md).

## Requirements

- Dolibarr 21 or later. The module is tested on Dolibarr 24.0.0 and 25.0.0 (alpha); 21 to 23 are accepted but not tested.
- PHP 8.1 or later, and a MariaDB or MySQL database (the module is tested on MariaDB).
- The Dolibarr modules **Third parties** and **Agenda**, enabled. The module refuses to be enabled without them.
- Optional, each for one feature: Proposals, Scheduled jobs, working e-mail sending, the E-mail collector with the PHP `imap` extension, the AI module. See [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Dolibarr settings the module relies on".

## 1. Get the module

Get the directory that contains `core/modules/modCrm.class.php`, for example with `git clone`, or by copying it. There is no packaged archive yet.

## 2. Put it where Dolibarr looks for external modules

Dolibarr loads external modules from `htdocs/custom`. The module must be reachable as **`htdocs/custom/crm`**: the directory name `crm` is the technical name of the module and cannot be changed.

Either keep the module outside the Dolibarr tree and link it (recommended: an update is then a plain `git pull`):

```text
ln -sfn /path/to/the/crm/directory /path/to/dolibarr/htdocs/custom/crm
```

or copy the directory to `/path/to/dolibarr/htdocs/custom/crm`.

Check two things:

- `htdocs/custom` is scanned when `$dolibarr_main_document_root_alt` in `htdocs/conf/conf.php` is empty, or when it points to that directory (`$dolibarr_main_url_root_alt='/custom'` and the matching path). Nothing else in `conf.php` is needed.
- The web server user can read the files (and follow the link). The module writes nothing to disk: its data is in the database.

### Keep the repository files private (production)

The web server serves every file of `htdocs/custom/crm`. When that is a link to a whole git checkout, a browser can also read `.git/` (history, remote URL), `doc/`, `test/`, `sql/`, `AGENTS.md` and the other `.md` files. None of it is secret code, but it tells an attacker the exact version and structure, and `.git/` can leak anything ever committed. On a production server do one of these:

- deploy only what runs (`admin/ class/ core/ css/ js/ langs/ lib/ sql/` and the `*.php` pages at the root) with a copy or `git archive`, not the checkout; or
- keep the checkout and deny those paths. The `.htaccess` at the root of the module does it for Apache 2.4, **if** the virtual host allows it (`AllowOverride All` or at least `AllowOverride AuthConfig`); with `AllowOverride None` it is ignored, so test it: `curl -I https://your-host/custom/crm/.git/config` must answer 403 or 404. For nginx:

```text
location ~ ^/custom/crm/(\.git|doc|test|sql|class|lib|core)(/|$) { deny all; }
location ~ ^/custom/crm/.*\.(md|sql|sh|lock|json|log|bak)$ { deny all; }
location ~ ^/custom/crm/(LICENSE|\.) { deny all; }
```

## 3. Enable the module

As a Dolibarr administrator: Home → Setup → Modules, family **CRM**, switch the module on. A top menu **CRM** appears.

The first enable:

- creates the tables `llx_crm_*` (`sql/llx_crm.sql`);
- creates the settings `CRM_*` with safe defaults (outreach off, e-mail steps as drafts, dry-run on, AI assistance off, no archive mailbox);
- seeds, once, a commercial pipeline with its stages, lead sources, qualification items and loss reasons;
- registers the menus, the rights, a home box, two scheduled jobs and the hooks (e-mail collector operation, general search entry, MCP tools);
- records the database schema version (`CRM_SCHEMA_VERSION`), which [UPDATE.md](UPDATE.md) uses.

It sends no e-mail and changes no third party, contact, proposal or user.

## 4. Grant the rights

Users → Permissions, family CRM. A salesperson needs "Read own deals", "Create and update deals" and "Create and complete next steps"; a manager adds "Read every owner", "Read CRM reports" and "Manage automated outreach"; the person who configures the module needs "Configure CRM". The full table is in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Rights".

## 5. First configuration

In this order:

1. **Dolibarr settings the module relies on**: the Scheduled jobs module and its security key, the e-mail setup (Home → Setup → Emails), each salesperson's e-mail address. See the guide.
2. **CRM → Setup**: check the Parameters, the stages of the pipeline, the qualification items, loss reasons and sources, and adapt them to your sales process.
3. **A system cron entry** that calls Dolibarr's scheduled jobs, otherwise no job runs (outreach, reminders, e-mail collector). See "Scheduled jobs" in the guide.
4. Only when you want them: the archive mailbox and the E-mail collector (e-mail history), the AI assistance, the MCP tools (step 7 below). Each has its own section in the guide.

Leave the dry-run settings on, and the e-mail steps as drafts, until you have checked the journal and the e-mail setup.

## 6. Check the installation

- The **CRM** menu is there and CRM → Home opens without error.
- CRM → Setup → **About** shows the module version `1.2.0` and a database schema "4 / 4, up to date".
- Home → Setup → Scheduled jobs lists two CRM jobs: "Owner reminders" and "CRM automated outreach".
- Create a test deal (CRM → Opportunities → New opportunity), plan a next step, move it between stages, then delete it.

## 7. Optional: let an AI assistant use the CRM (MCP)

The module adds 49 tools to the MCP server of Dolibarr's AI module (Dolibarr 24 or later): 20 read tools and 29 that create, change or delete data (deals, next steps, outreach and, with the "Configure CRM" right, the setup). Skip this step if you do not use MCP. Everything works without it.

On **Dolibarr 24.0.0** (steps that differ on 25 are noted):

1. **Enable the AI module** (Home → Setup → Modules). The CRM does not have to be enabled first or second: the module registers itself for the `aimcp` hook when it is enabled (step 3), and Dolibarr asks it for its tools each time the MCP server runs.
2. **Show the MCP setup pages.** On 24.0.0 the AI tabs "MCP server" and "Tool access control" only appear when the constant `MAIN_FEATURES_LEVEL` is **2** or more (Home → Setup → Other setup; on 25 it is 1). It also turns on other experimental Dolibarr features, so you can set it back once you are done. Without it you can open the pages by their address: `/ai/admin/server_mcp.php` and `/ai/admin/configure_tools.php`.
3. **Switch the MCP server on** (`/ai/admin/server_mcp.php`), generate the **shared MCP key** and choose the user the tools run as. On 24.0.0 the shared key is the only way to authenticate; personal API keys exist from 25. The key is a secret: keep it out of the project and out of chats.
4. **Give that user the CRM rights** it needs (step 4 above). Create a dedicated user for the assistant with exactly those rights: it decides what the assistant can do. A user with no CRM right sees none of the tools, and one without "Read every owner" only reaches its own deals. Dolibarr 24.0.0 does not check the rights a tool declares, and does not ask for a confirmation before a write, so the CRM tools check the rights themselves; the confirmation step exists from 25 only.
5. **Allow the tools** (`/ai/admin/configure_tools.php`, column "MCP Server"):
   - While nothing has ever been saved on that page, **every tool is allowed** (the setting `AI_MCP_SERVER_ALLOWED_TOOLS` is empty) and the CRM tools work at once.
   - As soon as the page has been used (a preset, or one tick), the setting becomes an explicit list and **every tool that is not in it is hidden**, the CRM tools included, and so is any tool a later update of the module adds. Tick the CRM tools you want, in the column "MCP Server".
   - The page labels the 20 read tools of the CRM "View only" and the 30 that create, change or delete data "Modify": the names start with a verb (`list_`, `get_`, or `create_`, `update_`, `delete_`, `add_`, `remove_`, `change_`, `send_`), which is what the page looks at. Its **"View only" preset** therefore allows only the read tools, but it **replaces the whole list**, the core tools too. To allow the tools that change data, tick them one by one, in steps, for a user whose rights you chose on purpose. The write tools are listed by right in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "MCP".
6. **Check.** `/ai/admin/configure_tools.php` lists the CRM tools (their names contain `crm`, for example `list_crm_opportunities`) even before you tick anything. The page shows every tool, whatever the allow-list and the user's rights. If none of them is there, the hook does not return them: see the table below. A client that calls `tools/list` on `/ai/server/mcp_server.php` with the key then gets the ticked tools the user has the rights for. A client that caches the list has to reconnect after a change.

More in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "MCP", with the rights each tool needs.

## Disabling and removing

Disabling the module (Home → Setup → Modules) removes the menus, rights, home box, hooks and the two scheduled jobs. It keeps the tables, the deals, the settings and the agenda events, and touches no third party, contact, proposal or user: enabling it again finds everything. There is no purge button. To remove the data for good, back up the database, then drop the `llx_crm_*` tables and delete the `CRM_*` settings by hand; agenda events whose type of element is `opportunity@crm` stay unless you delete them.

## If something goes wrong

| Symptom | Likely cause | What to do |
| --- | --- | --- |
| The module is not in the list | The directory is not `htdocs/custom/crm`, or `custom` is not scanned | Check the name, the link and `conf.php` (step 2) |
| It cannot be enabled | Third parties or Agenda is off, PHP below 8.1, Dolibarr below 21 | Enable the dependencies, check the versions |
| "Include of main fails" on a page | The module is not served from the Dolibarr tree | Use `htdocs/custom/crm` (step 2) |
| Enabling stops with "CRM schema step N failed" | The database user cannot alter tables, or the table is in an unexpected state | Fix the cause shown in the message, then enable again: it resumes at that step |
| No **CRM** menu for a user | No CRM right | Step 4 |
| No CRM tool in `ai/admin/configure_tools.php` | The files are an old copy (before the tools were returned the way Dolibarr 24.0.0 requires) or PHP still serves cached ones, the module was enabled without the `aimcp` hook, or an error in the hook (written to the Dolibarr log) | Use the current files and reload PHP, check that the constant `MAIN_MODULE_CRM_HOOKS` contains `aimcp` (disable and enable CRM if not), read the log |
| The CRM tools are on that page but not in the client | Not ticked in the "MCP Server" column, or the MCP user lacks the right | Step 5 and step 4 of the MCP section |

More cases are in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Troubleshooting".
