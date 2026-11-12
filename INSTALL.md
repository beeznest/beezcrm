# Installing the CRM module

For an administrator who installs the module on a Dolibarr instance for the first time. To update an installation that already exists, read [UPDATE.md](UPDATE.md). Everything you can configure afterwards is in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md).

## Requirements

- Dolibarr 21 or later. The module is tested on Dolibarr 24.0.0 and 25.0.0 (alpha); 21 to 23 are accepted but not tested.
- PHP 8.1 or later, and a MariaDB or MySQL database (the module is tested on MariaDB).
- The Dolibarr modules **Third parties** and **Agenda**, enabled. The module refuses to be enabled without them.
- Optional, each for one feature: **Tags/Categories** (from v1.2.0: colours under the account names, categories on the deal card), Proposals, Scheduled jobs, working e-mail sending, the E-mail collector with the PHP `imap` extension, the AI module. See [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Dolibarr settings the module relies on".

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

Users → Permissions, family CRM. (From v1.2.0, an **outside agent** who phones the prospects gets only the three rights "assigned", "note" and "mystep", and nothing on Third parties: see "Outside agents" in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md).) A salesperson needs "Read own deals", "Create and update deals" and "Create and complete next steps"; a manager adds "Read every owner", "Read CRM reports" and "Manage automated outreach"; the person who configures the module needs "Configure CRM". The full table is in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Rights".

## 5. First configuration

In this order:

1. **Dolibarr settings the module relies on**: the Scheduled jobs module and its security key, the e-mail setup (Home → Setup → Emails), each salesperson's e-mail address. See the guide.
2. **CRM → Setup**: check the Parameters, the stages of the pipeline, the qualification items, loss reasons and sources, and adapt them to your sales process.
3. **A system cron entry** that calls Dolibarr's scheduled jobs, otherwise no job runs (outreach, reminders, e-mail collector). See "Scheduled jobs" in the guide.
4. Only when you want them: the archive mailbox and the E-mail collector (e-mail history), the AI assistance, the MCP tools (step 7 below). Each has its own section in the guide.

Leave the dry-run settings on, and the e-mail steps as drafts, until you have checked the journal and the e-mail setup.

### Folder for the files of the notes (from v1.2.0)

The files attached to the notes of a deal, and the files attached to the e-mails written in the e-mail editor (subfolder `mail/`), are stored in the Dolibarr **documents directory** (the value of `$dolibarr_main_data_root` in `conf.php`), under `crm/opportunity/<deal id>/`. The module creates the `crm` folder itself on the first upload, as the user the web server runs PHP as, so it only works if that user may write in the documents directory. Those files are customer documents: grant exactly that, and nothing more.

1. **Keep the documents directory outside the web root** and never serve it. Dolibarr requires it; check that `$dolibarr_main_data_root` is not under `htdocs` or under any directory the web server publishes. The files are only ever downloaded through `custom/crm/note_file.php`, which checks the user's right on the deal. Do not add an alias or a link to the folder, and do not rely on an `.htaccess` file to protect it.
2. **Give the folder to the web server user, and to nobody else.** Find that user (`www-data` on Debian and Ubuntu, `apache` on Red Hat and Fedora, the `user =` of the PHP-FPM pool when PHP runs under FPM), then, as root:

```bash
install -d -o www-data -g www-data -m 0750 /path/to/documents/crm
chown -R www-data:www-data /path/to/documents/crm
find /path/to/documents/crm -type d -exec chmod 0750 {} +
find /path/to/documents/crm -type f -exec chmod 0640 {} +
```

   Directories `0750` and files `0640`: the owner reads and writes, the group only reads, others have no access. Never use `777`, `666` or any right for "others", on the folder or on the documents directory above it: any local account, or any other site on the same server, could then read or replace customer documents. If a backup account must read the files, put it in the group of the folder (or use a group of its own with `0750`/`0640`) instead of opening the folder to everybody.
3. **Do not create the folder as another user.** A folder made by root, by an administrator's shell or by a command-line script (including the checks of `test/cli/`) belongs to that user and is closed to the web server, so every upload fails with a message about the documents directory. Run such commands as the web server user (`sudo -u www-data php ...`), and never run the checks on a production database; if it happened, apply the commands above.
4. **SELinux** (Red Hat family): keep it enforcing and give the folder the label the web server may write, for example `semanage fcontext -a -t httpd_sys_rw_content_t '/path/to/documents(/.*)?'` then `restorecon -R /path/to/documents`.
5. **PHP**: `upload_tmp_dir` (the default is the system temporary folder) must exist and be writable by the web server user only (`0700`); keep `open_basedir`, if you use it, listing the documents directory. The size limit of one file is Dolibarr's `MAIN_UPLOAD_DOC` (2 MB by default), capped by `upload_max_filesize` and `post_max_size`.
6. **Check**: `stat -c '%A %U:%G' /path/to/documents/crm` shows `drwxr-x--- www-data:www-data`; `sudo -u www-data test -w /path/to/documents/crm && echo writable` answers `writable`; then attach a small file to a note of a test deal.
7. **Back it up** with the database, with the owners and rights kept (`rsync -a`, `tar`), and protect the backups as you protect the database: they hold customer documents.

### Categories (tags) of the accounts (from v1.2.0)

The CRM uses the categories of Dolibarr's own **Tags/Categories** module (Home → Setup → Modules → Tools), type **Customers/Prospects**: it adds no table. Without the module nothing about categories shows in the CRM.

1. **Enable Tags/Categories** (Home → Setup → Modules). Dolibarr adds the rights "Read categories", "Create/modify categories" and "Delete categories" to Users → Permissions.
2. **Create the categories** in Tools → Tags/Categories → Customers/Prospects, with the **colour** field of each one; a category with a colour underlines the account name in the CRM, one without a colour is only a label. Parents and children are supported: for example **Language** (Francophone, Dutch-speaking...) and **Interest** (Hot, Warm, Cold).
3. **A degree with a single answer** (Hot, Warm *or* Cold): in CRM → Setup → Parameters, **Mutually exclusive category groups**, tick the parent category (here "Interest"). The deal card then offers its sub-categories as radio buttons.
4. **Rights of the salespeople.** Give them the Dolibarr right **Read categories** (Users → Permissions, family Categories), so that the tags show on the cards of Dolibarr itself (the Prospect tab of the third party). **Do not give them "Create/modify categories"**: that right opens every category of every third party in Dolibarr, whoever owns the deal. The CRM lets a salesperson who may write deals choose the categories of the account **of the deals they own** (everybody's with "Read every owner") from the deal card, and for that it asks for no Dolibarr right on categories. Creating categories and colours stays with whoever holds "Create/modify categories" (the administrator).

## 6. Check the installation

- The **CRM** menu is there and CRM → Home opens without error.
- CRM → Setup → **About** shows the module version `1.2.0` and a database schema "8 / 8, up to date".
- Home → Setup → Scheduled jobs lists two CRM jobs: "Owner reminders" and "CRM automated outreach".
- From **outside your network**, `curl -i 'https://your-host/custom/crm/optout.php?h=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'` answers `404` with a short page saying the link is not valid: the public "not interested" page of the e-mails is reachable without a login. A `$dolibarr_main_url_root` that prospects cannot reach makes the link of every e-mail useless.
- Create a test deal (CRM → Opportunities → New opportunity), plan a next step, move it between stages, add a note with a small attached file (see "Folder for the files of the notes" if it fails), then delete it.

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
