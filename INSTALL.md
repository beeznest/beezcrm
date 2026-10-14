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

## 3. Enable the module

As a Dolibarr administrator: Home → Setup → Modules, family **CRM**, switch the module on. A top menu **CRM** appears.

The first enable:

- creates the tables `llx_crm_*` (`sql/llx_crm.sql`);
- creates the settings `CRM_*` with safe defaults (sequences off, e-mail steps as drafts, dry-run on, AI assistance off, no archive mailbox);
- seeds, once, a commercial pipeline with its stages, lead sources, qualification items and loss reasons;
- registers the menus, the rights, a home box, two scheduled jobs and the hooks (e-mail collector operation, general search entry, MCP tools);
- records the database schema version (`CRM_SCHEMA_VERSION`), which [UPDATE.md](UPDATE.md) uses.

It sends no e-mail and changes no third party, contact, proposal or user.

## 4. Grant the rights

Users → Permissions, family CRM. A salesperson needs "Read own deals", "Create and update deals" and "Create and complete next steps"; a manager adds "Read every owner", "Read CRM reports" and "Manage customer sequences"; the person who configures the module needs "Configure CRM". The full table is in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Rights".

## 5. First configuration

In this order:

1. **Dolibarr settings the module relies on**: the Scheduled jobs module and its security key, the e-mail setup (Home → Setup → Emails), each salesperson's e-mail address. See the guide.
2. **CRM → Setup**: check the Parameters, the stages of the pipeline, the qualification items, loss reasons and sources, and adapt them to your sales process.
3. **A system cron entry** that calls Dolibarr's scheduled jobs, otherwise no job runs (sequences, reminders, e-mail collector). See "Scheduled jobs" in the guide.
4. Only when you want them: the archive mailbox and the E-mail collector (e-mail history), the AI assistance, the MCP server. Each has its own section in the guide.

Leave the dry-run settings on, and the e-mail steps as drafts, until you have checked the journal and the e-mail setup.

## 6. Check the installation

- The **CRM** menu is there and CRM → Home opens without error.
- CRM → Setup → **About** shows the module version `1.0.0` and a database schema "1 / 1, up to date".
- Home → Setup → Scheduled jobs lists two CRM jobs: "Owner reminders" and "Customer sequences".
- Create a test deal (CRM → Opportunities → New opportunity), plan a next step, move it between stages, then delete it.

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

More cases are in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md), "Troubleshooting".
