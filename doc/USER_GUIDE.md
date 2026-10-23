# CRM user guide: following a prospect, start to finish

This guide is for the person who uses the CRM module day to day, and in particular for a salesperson who is new to the job and to the tool. It follows one prospect from first contact to a signed or lost deal, and says why each step exists. The menus are under **CRM** in the top bar. If a button or a menu is missing for you, your administrator has not given you the matching right: see the [administrator guide](ADMIN_GUIDE.md), section "Rights". Setting the module up is also described there, not here.

## Where to find things

All menus are under **CRM** in the top bar.

| Menu | What it is for |
| --- | --- |
| **Home** | Your work to do (deals with no next step, overdue steps, past expected close, rotting) and the forecast |
| **Pipeline** | The board: one column per stage, one card per open deal. Drag a card to move it |
| **Opportunities** | The list of deals, filtered by status (open, won, lost, all) and by a search text, sorted by clicking a column title and split in pages (the number of rows per page is the one of your Dolibarr user settings, changeable above the list). **New opportunity** is here |
| **Automated outreach** | Automatic follow-up plans. You only see this one in use if you manage them |
| **Activities** | Your next steps and history, with filters: overdue, today, planned, done; sortable and in pages like the deal list |
| **Reports** | Win rate, conversion, loss reasons and more. Needs a right your administrator gives |
| **Setup** | For administrators only |

The general search box at the top of Dolibarr also has an entry **CRM opportunities** (shortcut `O`): type a reference, a title, an account name or a contact name, and choose it. The list then shows the matches in every status. It only shows deals you may see.

**On the Prospect tab of a third party (from v1.2.0).** Open a third party in Dolibarr and click its **Prospect** tab (the **Third party** tab is not changed). When it has deals you may see, the CRM adds two things to that tab:

- in the table of the basic data, a line **CRM opportunity** with the reference of its deals, each one a link to the deal in the CRM, with its title and its stage (open deals first, three at most, then a link "n more..."). A prospect with no deal shows a link **New opportunity** instead, if you may create deals;
- at the bottom of the right column, after the lists of recent proposals and orders, the block **CRM history**: the last **5** things that happened on the deals of this third party, newest first: stage moves and the steps and e-mails that are done. Each line gives the date, the deal (a link), what happened and who did it. The link **Full CRM history** at the top right of the block opens the whole list, in pages.

Nothing shows if the CRM module is off or if you have no CRM right. You only see your own deals unless you have "Read every owner". Planned steps are not history: they stay on the deal card and in **Activities**.

**The "?" button.** Most pages carry long explanations of what a list or a field is for. To keep the screens light, they are **hidden** by default. A **"?" button** appears in the top right corner of the page title bar whenever the page has some; click it to show them, click it again to hide them. It does not remember your choice from one page to the next. Short hints and warnings are always visible.

## The vocabulary

- **Account**: the company you sell to. It is a normal Dolibarr third party, so the same record is used by invoices and by your colleagues.
- **Contact**: the person you talk to at that company.
- **Deal** (also called opportunity): one chance to sell something to that account. One account can have several deals.
- **Stage**: where the deal stands in your sales process. Each stage has a probability of winning. With the standard setup: New 10%, Replied to outreach 15%, Qualified 25%, Proposal sent 50%, Negotiation 75%, then Won and Lost. **Replied to outreach** is where a deal lands by itself when the prospect answers an approach (an e-mail of an outreach, or any e-mail recorded on the deal): it is the signal to qualify the lead. A deal that is already further on stays where it is.
- **Next step**: the next thing you will do on the deal, with a date: a call, a meeting, a task or an e-mail to write. It is an event in the Dolibarr agenda.

## Three rules that make everything else work

1. **Every open deal has a dated next step.** A deal with nothing planned stops moving, and nobody notices until the customer buys elsewhere. The tool will refuse to change the stage of an open deal that has no future next step, and this is the reason.
2. **Qualify before you spend time on a proposal.** A proposal for someone with no need, no budget or no decision power is a lot of work that ends in "no answer".
3. **Keep the data honest.** Your amount, expected close date and stage feed the forecast that your manager uses to plan. A deal left at 75% for six months, or a lost deal left open, makes the forecast wrong for everyone.

## Step 1. Register the prospect and open the deal

**Why.** If it is not in the tool, nobody can follow it up, and a colleague may call the same person the same week.

**How.** CRM → Opportunities → New opportunity.

1. **Search the account before creating it.** The "Account" list is searchable and shows who is already a prospect or a customer. Type part of the name. A duplicate splits the history of one company in two.
2. If the company is really not there, use the **New prospect** button next to the list. Dolibarr's third-party form opens, set as a prospect. Save it and you come back to the deal with the prospect already selected; what you had typed is kept. The button only shows if you may create third parties.
3. Fill the deal:
   - **Title**: something you will recognise in a list ("Website redesign", not "Deal").
   - **Pipeline**: the sales process to use (new business, renewal, partners...). When unsure, take the first.
   - **Contact**: the person you deal with. Choose one when you can: automatic follow-up e-mails go to this contact, and with no contact no e-mail is sent.
   - **Amount**: your honest estimate of what the sale is worth. It is a first guess; you will correct it.
   - **Expected close**: the date you realistically expect a decision. By default it is a month from today.
   - **Source**: where the lead came from (website, referral, event...). Reports later show which sources really bring customers, so do not skip it.
   - **Owner**: you. Only a manager can set someone else.
   - **Description** (before v1.2.0) / **First note** (from v1.2.0): what you know so far, in a few lines: who asked, about what, how urgent. From v1.2.0 it becomes the first of the deal's internal notes, see below.
4. Save. The deal starts in the first stage, usually **New**.

### Internal notes (from v1.2.0)

From v1.2.0 the "Description" of a deal is replaced by **Internal notes**: a list of notes that only your team sees (nothing is sent to the customer). On the deal card, the section **Internal notes** shows:

- the notes in **chronological order**, right under the data of the deal: only the **latest note** is shown, with its author, its date and time, its text and its files. A link **Show the n earlier notes** above it unfolds the others, oldest first, before the latest one, and folds them again;
- under the notes, a box to **add a note**, with an optional choice of files to attach to it (several at once; the size limit is the one of Dolibarr's documents, 2 MB unless your administrator changed it; at most 10 files per note);
- for each file, its name (click to download it), its size and, for a PDF, a **magnifier icon** (the same as in Dolibarr's document lists) that opens the PDF in a window of the page itself, without leaving the card and without downloading it;
- a **paperclip with a number** next to the section title: click it to open the list of **all the files of the deal** (name, size, date added, author, and the note it belongs to), with the same name link to download and magnifier icon to preview.

**Editing a note.** The pencil icon of a note turns it into a form: change the text, add files, remove a file (trash icon), and save. Only the **author** of a note, or a manager who reads every owner's deals, may edit it, and you need the right to create and update deals. The note then shows "edited on ... by ...". The **earlier text is not lost**: the link **history (n)** under the note lists every version, newest first, with who wrote it and when. Notes are never deleted from the card; a note written by mistake is corrected by editing it. Deleting the whole deal deletes its notes and files.

## Step 2. Plan the first contact

**Why.** The deal is now open, so rule 1 applies from the start.

**How.** On the deal card, section **Next step**: pick the kind (call, meeting, task, e-mail), give it a title and a date, and press Create. For example: "Call to understand the need", tomorrow.

**Change or remove a next step.** In the same list, a planned step has two more icons next to the check mark: **Modify** (the row becomes a small form: kind, title, date and time) and **Delete** (asks for a confirmation). Postponing a call is a change of date, not a new step. A planned e-mail only has its date changed here; its subject and text are edited in the e-mail editor (envelope icon). A step that is done is history and can be neither changed nor deleted. You may only act on steps of deals you are allowed to see.

Note that "e-mail" here is a reminder for you to write one; it does not send anything.

When you have done it, click the **check icon** (tooltip "Complete") next to it (on the card, or in CRM → Activities). Then **immediately plan the next one**. Doing the action without planning the next is how deals go cold.

## Step 3. Qualify

**Why.** Four short questions tell you if the deal deserves a proposal: is there a **need**, is there a **budget**, do you know the **decision maker**, and is there a **timeline**. Each confirmed item is worth 25 points; the score is only a guide. The card lists what is still missing, which is your agenda for the next conversation.

**How.** During the first calls, ask. When the customer has told you, tick the item on the card (section Qualification) and save. Tick only what the customer has actually said, not what you hope. An administrator can change the list.

When the need is confirmed and the deal looks real, move it to **Qualified** (see Step 5 for how to move a deal).

## Step 4. Make the proposal

**Why.** A proposal turns a conversation into a price the customer can accept. Keeping it tied to the deal means everyone sees what was offered.

**How.**

1. Create the proposal in the normal Dolibarr way (Commercial → Proposals) for the **same account**.
2. Back on the deal card, section **Proposals**, enter the proposal's id and press **Link proposal**. Its total is shown next to the deal. It does not overwrite the deal's amount: if the proposal changes your estimate, update the amount yourself (Modify). One deal can have several proposals, for example a revised offer.
3. Move the deal to **Proposal sent**.
4. **Plan the follow-up now**: a call in two or three days to ask whether they received it and what they think. A proposal that is sent and then left alone often goes unanswered.

## Step 5. Follow up, negotiate, move the deal

**How to move a deal.** Two ways:

- On the deal card, section **Stage**: choose the stage and press **Move**.
- In CRM → Pipeline, which shows one column per stage (a stage your administrator has retired shows only while deals are still in it; move them on): **drag the card** to the next column. Use the filters to see only one pipeline or, with the right, one owner.

Moving is allowed to any open stage, forward or back, as long as the deal has a future next step. If you get the message asking you to add one, plan it and try again. Closing a deal as lost cannot be done by dragging, because a reason is required (see Step 6).

**Move a deal when something real happened, not when you feel optimistic.** For instance: Negotiation when the customer discusses price or terms with you.

**Customer e-mails.** If your administrator has set up the archive mailbox, e-mails sent by the tool are stored on the deal with their full text. When you answer a customer from your own mail program, answer **in the same thread** and put the archive address in copy: the message is then added to the deal too. **From v1.2.0**, a message written from scratch is recognised too when it goes to (or comes from) the **contact of the deal**, at the e-mail address saved on the contact, and that contact has only one open deal: no marker is needed, the archive address in copy or hidden copy is enough. If the contact has two open deals, add the deal reference in square brackets to the subject. Before v1.2.0, or for any other recipient, a new message cannot be recognised. An answer from the customer is recorded the same way, and it stops any automatic outreach on the deal, so the customer is not chased after they replied.

**Automatic outreach.** Your manager may enrol deals in an outreach: a planned series of reminders, calls and e-mails after a set number of days. It saves you from forgetting, but it does not replace a real conversation. Calls and tasks of an outreach appear as next steps on the deal.

**Enrol a lead in an outreach from its card.** The section **Automated outreach** of the deal card shows the outreach the lead is enrolled in, its status, the step it waits for and when that step falls due. When the lead follows none and the deal is open, a list offers the outreach that can be used, and **Enrol from now** enrols the lead: the enrolment starts at that moment, and the first step falls due after its delay counted from then. You need the right "Manage automated outreach". A lead follows one outreach at a time; it stops by itself when the prospect replies or the deal is won or lost, and can then be enrolled in another one. A warning tells you when the contact has no e-mail address, since the e-mail steps cannot be sent without one.

**What an outreach sent to a prospect.** On the deal card, under **History**, the list **E-mails from automated outreach** shows every e-mail an outreach made for this deal, newest first: the date, the outreach, the step and the day of the outreach that caused it ("Step 2, day 3" is the second step, three days after the deal was enrolled), the address, the subject and the result: sent, simulated, or left as a draft. The eye icon opens the e-mail as it was made, with the name, the deal and the amount already filled in. A simulated e-mail was not sent, and the page says so. Nothing is sent from that page.

**E-mails suggested by an outreach.** By default the tool does not send anything by itself. When an e-mail step is due, it appears on the deal as a planned e-mail (in the Next step list, in CRM → Activities and, once late, on Home), with the suggested text already filled in. Click the **envelope icon** (tooltip "Write / send"), or the title of the e-mail, to open it. Read it, change it, and choose:

- **Send from here**: Dolibarr sends it to the address shown (the deal's contact by default) after a confirmation. The **Sender** list at the top of the editor is the address the message is sent from. It offers, when they exist: the default address of the CRM, **your own address** (the e-mail of your user: add it to your profile if the list does not offer it) and the **mailbox of the lead e-mail collector**. The confirmation names the address you picked, and your choice is kept for your next e-mails. The box **Send me a hidden copy (Bcc)** is ticked by default: the message is also sent, as a hidden copy, to the e-mail address of your user, so that you keep it in your own mailbox. Untick it for a message you do not want a copy of. The copy only goes with "Send from here" and needs an e-mail address in your profile. Replies go to the address you chose: if you pick your own, the CRM records the answer on the deal only when it also reaches the mailbox the e-mail collector reads. If the editor says that no sender is set, ask your administrator: the message cannot be sent from here until one is. It is recorded on the deal and the draft disappears.
- **Open in my mail client**: your mail program opens with the recipient, subject and text filled in, and the archive address in copy. Send it from there. **Keep the subject**: it contains the deal reference in square brackets, such as `[OPP2026-0001]`, which is how the message finds its deal again. **Keep the archive address in copy.** Then come back and press **E-mail sent manually**, so the step is closed. If the text is too long for your mail program, use **Copy text** and paste it.
- **E-mail sent manually**: registers the e-mail as sent now. A few minutes later, when the archive mailbox is read, the real message (with its exact text and date) replaces this entry, so the history does not show it twice. If you forgot to press the button, the real message still replaces the draft, as long as the subject is the same.
- **Delete this step**: removes the planned e-mail from the deal completely. Use it only if you no longer want that step; to come back to it later, use the link back to the deal instead.
- **Save draft**: keeps your changes for later.

**Formatted or plain.** The **Format** choice sets how the message is sent. **Formatted (HTML)** gives you an editor like a word processor (bold, lists, links, colours) in the first tab; the message is sent with a plain-text version that is made automatically, so a person whose mail program shows no formatting still reads it. You can see that version in the second tab, but not edit it: change the formatted tab instead. **Plain text only** sends exactly what is in the plain-text tab. Switching back to plain text loses the formatting, and the editor asks you first. In a formatted message, write a web address in full in the text when it matters, because the plain-text version shows only the words of a link. You cannot insert images. If you send from your own mail client, "Open in my mail client" fills in the plain text only; use **Copy text** to copy the formatted version and paste it into your message.

**Let the AI propose a reply.** If your administrator has switched it on (it is off by default), the e-mail editor has an **AI assistant** block. It sends the deal summary, the e-mails already exchanged and your current text to the AI service, and asks it to propose the next e-mail. A short notice names the service and tells you whether names are masked before sending. The block shows a **prompt**, the instruction given to the AI (for example tone, length, what to answer). It is pre-filled; edit it if you want something different, such as "shorter", "firmer on the price" or "propose a call on Thursday". Press **Suggest a reply with AI**: the proposal replaces the text above it. **Nothing is sent or saved**: read it, correct it, check every price, date and promise, then use the buttons as usual. If you do not like it, change the prompt and ask again, or reload the page to get your saved draft back. **Restore the default prompt** puts back the standard instruction. Never paste into the prompt anything that must not leave the company.

The customer's reply is recognised by the same reference in the subject, so it is added to the deal when it reaches the archive mailbox. If a contact answers you by another way, ask whoever manages outreach to use **Mark replied** on the outreach so that it stops (it needs the outreach right).

## Step 6. Close the deal

**Won.** Move the deal to **Won**. The date is recorded and the deal leaves the forecast and the board. Then continue in Dolibarr as usual (order, invoice, contract). Do not delete the deal: it feeds the win-rate and sales-cycle reports.

**Lost.** Open the deal card, choose **Lost** in the Stage section, **pick the loss reason** (price, no budget, competitor, timing, no decision, no answer...) and add a note if useful. Be truthful: the reasons are summed up in a report, and that is how the company learns whether it loses on price or on slow follow-up. A reason like "no answer" is allowed; an invented one hides the problem.

Do not leave a dead deal open "just in case". If the prospect may come back later, close it as lost with the right reason and open a new deal when they do.

## Reports

If you have the right to read reports (CRM → Reports), you see, for a period and a pipeline you choose:

- **Forecast**: open deals by month of expected close, in the coming months.
- **Cohort conversion**: of the deals created in the period, how many reached each stage. This follows each deal's history; it is not a count of the cards in each column.
- **Win rate**: deals won divided by deals won plus lost, by closing date.
- **Cycle time**: the average number of days from creation to win, on won deals only.
- **Loss reasons**: how many deals were lost for each reason, and the amount.
- **By owner** and **by source**: open, won and lost deals, with amounts, for each salesperson and each lead source.

These figures are only as good as the data you enter: stages moved when something really happened, loss reasons that are true, a source on every deal.

## Your daily and weekly routine

**Every day, ten minutes: CRM → Home.** Each list is work to do:

- **No next step**: open deals with nothing planned. Plan something for each one.
- **Overdue**: next steps whose date has passed. Do them, complete them, or move the date by planning a new one. CRM → Activities shows the same with filters (overdue, today, planned, done).
- **Past expected close**: the date you gave has passed. Either the date was wrong (update it) or the deal is stuck (act, or close it).
- **Rotting**: no completed activity for longer than the stage allows. Contact the customer, or decide that the deal is dead.

The home box of Dolibarr's main page shows your overdue steps and deals without a next step too. If your administrator has enabled owner reminders, you also get a daily summary e-mail.

**Every week, fifteen minutes.** Open CRM → Pipeline, check each column and ask, for every card: is the stage still true, is the amount still right, is the close date still realistic? Then look at the forecast at the bottom of Home. The **weighted** amount is the amount multiplied by the probability of the stage; it is a more honest figure than the sum of amounts. Deals are also split by month of expected close and by category (pipeline, upside, commit), where *commit* is what you would bet on.

## When something does not work

| You see | Why | What to do |
| --- | --- | --- |
| "Add a future next step before moving this opportunity" | The deal has no unfinished event dated today or later | Plan a next step on the card, then move |
| A card refuses to drop in **Lost** | Lost needs a reason | Use the Stage section of the card |
| No **New opportunity**, **Move** or **Link proposal** button | You do not have the right to create and update deals | Ask your administrator (see the administrator guide, "Rights") |
| You cannot find a colleague's deal | You only see your own deals unless you have "Read every owner" | Ask the owner or your manager |
| The customer replied but nothing is on the deal | The message did not reach the archive mailbox, or it is not in a thread already on the deal | Forward it to the archive address, from the thread. From v1.2.0, a message to or from the deal's contact is found without a thread, unless that contact has several open deals: then keep `[OPP…]` in the subject |
| An error after **Link proposal** | The proposal is already linked to this deal | Nothing to do: it is already there |
| The linked proposal shows the wrong ref or total | The id is not checked when linking, so a mistyped id links another proposal | Unlink it and link the right id, read on the proposal's own page |
