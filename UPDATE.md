# Updating the CRM module

For an administrator who already runs the module and installs a newer version. To install it for the first time, read [INSTALL.md](INSTALL.md).

## How it works

Dolibarr has no upgrade mechanism for an external module: it keeps no version of the module in its database and does not notice that the files changed. The module therefore carries its own, in three parts:

- the **module version** (`$this->version` in `core/modules/modCrm.class.php`, listed in [ChangeLog.md](ChangeLog.md)), which tells people what changed;
- a **database schema version**, a whole number that grows by one with each change of the tables. The code expects a number (`crmSchemaCurrent()`); the database keeps the number it has reached in the setting `CRM_SCHEMA_VERSION`. Several module versions can share a schema version;
- a numbered list of **schema steps** (`crmSchemaSteps()` in `lib/crm.lib.php`): "version 2: add this column", and so on.

New files are active at once, but the database changes only when the module is **disabled and enabled again**. That re-enable runs every step newer than the installed number, in order, once each, and records the number after each step that worked. Until then the module keeps working without the new feature, and a notice on CRM → Home and CRM → Setup (shown to people who hold "Configure CRM") says "the module has been updated and its database must be upgraded".

## Update procedure

1. **Read** the section of [ChangeLog.md](ChangeLog.md) for each version between yours and the new one, and check the requirements in [INSTALL.md](INSTALL.md) (Dolibarr and PHP versions).
2. **Note** your current state: CRM → Setup → **About** shows the module version and the schema, "installed / expected".
3. **Back up** the database (a full dump) and the module directory. If you can, run the update first on a copy of the instance.
4. **Replace the files**: `git pull` in the module directory, or copy the new version over the old one. If the module is a link from `htdocs/custom/crm`, keep the link. The new code is live now.
5. **Disable the module, then enable it again**: Home → Setup → Modules, family CRM. This applies the schema steps and registers again what a version can change: rights, menus, the home box, scheduled jobs and hooks.
6. **Check**: CRM → Setup → About shows "up to date" (installed = expected), the notice has gone, Home → Setup → Scheduled jobs still lists the two CRM jobs, and CRM → Home and a deal card open without error.

7. **MCP only:** if you use the MCP tools and the tool access control already holds a list (`ai/admin/configure_tools.php`), a tool that the new version adds is hidden until you tick it there. The changelog names the new tools. Reload PHP if it caches files (opcache) so that the new tool classes are read. See [INSTALL.md](INSTALL.md), step 7.

You can skip versions: going from 1.0.0 to a much later one runs every missing step in order. Going from 1.0.0 to 1.1.0 runs schema steps 2 and 3: the content of the outreach journal, then the reply stage (see "1.1.0" in [ChangeLog.md](ChangeLog.md)); the new stage is added to every pipeline that has an open stage and no reply stage, so look at your pipelines afterwards if you want it elsewhere or under another name.

Going to **1.2.0** runs schema step 4 (internal notes): Dolibarr creates the tables `llx_crm_note`, `llx_crm_note_revision` and `llx_crm_note_file` when the module is enabled again, then the step turns the description of every deal that has one into its first note (written by the creator of the deal on its creation date). The column `description` stays in the table, unused. The files of the notes are stored in the documents directory of Dolibarr, under `crm/opportunity/<deal id>/`: include that directory in your backups.

Going to **1.2.0** also runs schema step 5 (the public "not interested" link): the table `llx_crm_optout` is created when the module is enabled again, and the loss reason **Not interested** (code `NOTINTERESTED`) is added to each entity that has a pipeline. From then on every e-mail sent for a deal ends with the link, so check that `custom/crm/optout.php` is reachable by your prospects without a login (see "The public \"not interested\" link" in [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md)). Until the module is enabled again the e-mails go out without the link.

Going to **1.2.0** also runs schema step 6 (notes as agenda events): the column `llx_crm_note.fk_action` is added, then an agenda event "CRM note added" is made for every existing note (dated like the note, in the name of its author, on the deal and its third party). For a base with many notes the step takes a moment; a note whose event cannot be made is left without one and gets it at its next edit.

Going to **1.2.0** also runs schema step 7 (internal reference of a deal): the column `llx_crm_opportunity.ref_internal` is added. It is empty for the existing deals. To make the reference a link, set the **Base address of the internal references** in CRM → Setup → Parameters.

Going to **1.2.0** also runs schema step 8 (attachments of the e-mails of the editor): the table `llx_crm_mail_file` is created when the module is enabled again. The attached files are kept in the documents directory of Dolibarr under `crm/opportunity/<deal id>/mail/`: they are part of the backup like the files of the notes.

## What an update does and does not touch

- It **creates** what is missing: new columns, new settings with their defaults, new rights.
- It **keeps** your deals, stages, pipelines, outreach, agenda events, settings (a setting you changed keeps your value) and the rights you gave to users. It seeds the sample pipeline, sources, loss reasons and qualification items **only** when the entity has no pipeline, so it never adds them again.
- It **never drops** a table or a column on its own.
- It sends nothing: no e-mail goes out because of an update.

## If a step fails

Enabling stops with a message "CRM schema step N (label) failed: reason", and the module stays disabled. The steps before N are applied and recorded. Fix the cause (typically a database user without the right to alter tables, or a table changed by hand), and enable the module again: it resumes at step N. If you cannot fix it, restore the backup of step 3 (database and files).

## Going back

A downgrade is not supported: an older version does not know the newer tables. Restore the database and the files from the backup made before the update.

## Other things that need a re-enable

- After a **Dolibarr upgrade**, Dolibarr does not reload external modules. Check the version of Dolibarr against [INSTALL.md](INSTALL.md), and disable and enable the module once if a hook (e-mail collector operation, search entry) or a menu is missing.
- With **several entities** (Multicompany), the schema version is kept per entity but the tables are shared: enable the module again in each entity. The steps are safe to run twice, so the second entity simply records the number.

## For the person who releases a version

1. Set `$this->version` in `core/modules/modCrm.class.php` and add a section for it at the top of `ChangeLog.md`.
2. If the **tables change**:
   - put the final form in `sql/llx_crm.sql`, which creates the tables of a new installation;
   - increase `crmSchemaCurrent()` by one and add the matching entry to `crmSchemaSteps()`, with a function that makes the change on an existing database;
   - write the step so that it is **safe to run on a database that already has its result**: look before you change (does the column exist, is the value already converted). A new installation runs every step on tables that are already at the latest form, and a step may run twice after a failure;
   - never edit a step that has been released: add a new one;
   - keep a step small: one change, and a data change has its own step.
3. Make the code work **without** the change until the module is re-enabled (see `crmStepModeSupported()`), since new files are live before the database is upgraded.
4. Test the step on a copy: from the previous schema version, from nothing (a new installation), and twice in a row.
5. Update the guides if a setting, a right, a scheduled job or a hook changed, and mention in `ChangeLog.md` what an administrator has to do besides re-enabling.
