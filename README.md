# CRM

Sales pipeline module for [Dolibarr](https://www.dolibarr.org). Accounts, contacts, proposals and agenda events stay native Dolibarr objects. The module adds the **deal** (also called opportunity or lead), its **stage**, qualification, forecast and follow-up.

## What it does

- Several **pipelines**, each with its own stages, probabilities and forecast categories.
- A kanban **board** and a list of deals, with a next step required before an open deal changes stage.
- A short **qualification** checklist, **loss reasons** and **sources** for honest reports.
- **Next steps** as Dolibarr agenda events, shown on the deal, on the home page and in a home box.
- **Reports**: forecast, cohort conversion, win rate, cycle time, loss reasons, by owner and by source.
- **Automated sequences** of reminders, calls and e-mails, as drafts for the salesperson to review and send, or sent automatically.
- An e-mail editor with a **formatted (HTML) mode**, sent with an automatic plain-text version, or plain text.
- An optional **AI assistant** in the e-mail editor that proposes the next e-mail from the exchange so far, with an editable prompt, using the AI module of Dolibarr.
- **E-mail history** kept on the deal, read from a dedicated mailbox by Dolibarr's e-mail collector.
- **MCP tools** (`list_crm_opportunities`, `create_crm_activity`...) so an AI assistant can work on deals within the user's rights.
- An entry in Dolibarr's general **search box**.

## Documentation

| For | Read |
| --- | --- |
| The person who installs, configures and maintains the module | [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md) |
| The person who uses it every day, in particular a new salesperson | [doc/USER_GUIDE.md](doc/USER_GUIDE.md) |
| Why it is designed this way: data model, rules, decisions | [doc/STRUCTURE_ANALYSIS.md](doc/STRUCTURE_ANALYSIS.md) |
| What changed in each version | [ChangeLog.md](ChangeLog.md) |
| Installing the module | [INSTALL.md](INSTALL.md) |
| Updating the module | [UPDATE.md](UPDATE.md) |
| Developers and AI agents working on the code | [AGENTS.md](AGENTS.md) |

## Quick install

The full procedure is in [INSTALL.md](INSTALL.md); to update, see [UPDATE.md](UPDATE.md).

Requires Dolibarr 21 or later, PHP 8.1 or later, and the Dolibarr modules Third parties and Agenda.

```text
ln -sfn /path/to/this/directory /path/to/dolibarr/htdocs/custom/crm
```

Then, as an administrator, enable **CRM** in Home → Setup → Modules, grant the CRM rights to your users, and follow the [administrator guide](doc/ADMIN_GUIDE.md): it covers the Dolibarr settings to check first, the module setup page by page, the scheduled jobs, e-mail, the e-mail history and MCP.

Disabling the module keeps its tables and the agenda events; it never touches third parties, contacts, proposals or users.

## Layout

```text
core/      module descriptor and home box
class/     deals, pipelines, stages, sequences, reports, e-mail drafts and archive, hooks, cron, MCP tools
lib/       helpers (pure rules separated from the ones that need Dolibarr)
admin/     the Setup screen
sql/       tables
langs/     English and French, plus a machine translation for the other languages of Dolibarr
test/      unit tests of the pure rules, and command-line checks against a database
doc/       the guides above
```

The pages (`index.php`, `pipeline.php`, `opportunity_*.php`, `activity_list.php`, `sequence_*.php`, `report.php`, `email_compose.php`) sit at the root, which Dolibarr serves as `/custom/crm/`.

## License

GPL-3.0-or-later, like Dolibarr.
