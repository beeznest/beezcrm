# CRM user guide: following a prospect, start to finish

This guide is for the person who uses the CRM module day to day, and in particular for a salesperson who is new to the job and to the tool. It follows one prospect from first contact to a signed or lost deal, and says why each step exists. The menus are under **CRM** in the top bar. If a button or a menu is missing for you, your administrator has not given you the matching right: see the [administrator guide](ADMIN_GUIDE.md), section "Rights". Setting the module up is also described there, not here.

## Where to find things

All menus are under **CRM** in the top bar.

| Menu | What it is for |
| --- | --- |
| **Home** | Your work to do (deals with no next step, overdue steps, past expected close, rotting) and the forecast |
| **Pipeline** | The board: one column per stage, one card per open deal. Drag a card to move it |
| **Opportunities** | The list of deals, filtered by status (open, won, lost, all) and by a search text. **New opportunity** is here |
| **Activities** | Your next steps and history, with filters: overdue, today, planned, done |
| **Sequences** | Automatic follow-up plans. You only see this one in use if you manage them |
| **Reports** | Win rate, conversion, loss reasons and more. Needs a right your administrator gives |
| **Setup** | For administrators only |

The general search box at the top of Dolibarr also has an entry **CRM opportunities** (shortcut `O`): type a reference, a title, an account name or a contact name, and choose it. The list then shows the matches in every status. It only shows deals you may see.

## The vocabulary

- **Account**: the company you sell to. It is a normal Dolibarr third party, so the same record is used by invoices and by your colleagues.
- **Contact**: the person you talk to at that company.
- **Deal** (also called opportunity): one chance to sell something to that account. One account can have several deals.
- **Stage**: where the deal stands in your sales process. Each stage has a probability of winning. With the standard setup: New 10%, Qualified 25%, Proposal sent 50%, Negotiation 75%, then Won and Lost.
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
   - **Description**: what you know so far, in a few lines: who asked, about what, how urgent.
4. Save. The deal starts in the first stage, usually **New**.

## Step 2. Plan the first contact

**Why.** The deal is now open, so rule 1 applies from the start.

**How.** On the deal card, section **Next step**: pick the kind (call, meeting, task, e-mail), give it a title and a date, and press Create. For example: "Call to understand the need", tomorrow.

Note that "e-mail" here is a reminder for you to write one; it does not send anything.

When you have done it, press **Complete** next to it (on the card, or in CRM → Activities). Then **immediately plan the next one**. Doing the action without planning the next is how deals go cold.

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
- In CRM → Pipeline, which shows one column per stage: **drag the card** to the next column. Use the filters to see only one pipeline or, with the right, one owner.

Moving is allowed to any open stage, forward or back, as long as the deal has a future next step. If you get the message asking you to add one, plan it and try again. Closing a deal as lost cannot be done by dragging, because a reason is required (see Step 6).

**Move a deal when something real happened, not when you feel optimistic.** For instance: Negotiation when the customer discusses price or terms with you.

**Customer e-mails.** If your administrator has set up the archive mailbox, e-mails sent by the tool are stored on the deal with their full text. When you answer a customer from your own mail program, answer **in the same thread** and put the archive address in copy: the message is then added to the deal too. A new message written from scratch cannot be recognised. An answer from the customer is recorded the same way, and it stops any automatic sequence on the deal, so the customer is not chased after they replied.

**Automatic sequences.** Your manager may enrol deals in a sequence: a planned series of reminders, calls and e-mails after a set number of days. It saves you from forgetting, but it does not replace a real conversation. Calls and tasks of a sequence appear as next steps on the deal.

**E-mails suggested by a sequence.** By default the tool does not send anything by itself. When an e-mail step is due, it appears on the deal as a planned e-mail (in the Next step list, in CRM → Activities and, once late, on Home), with the suggested text already filled in. Click **Write / send** to open it. Read it, change it, and choose:

- **Send from here**: Dolibarr sends it to the address shown (the deal's contact by default) after a confirmation. It is recorded on the deal and the draft disappears.
- **Open in my mail client**: your mail program opens with the recipient, subject and text filled in, and the archive address in copy. Send it from there. **Keep the subject**: it contains the deal reference in square brackets, such as `[OPP2026-0001]`, which is how the message finds its deal again. **Keep the archive address in copy.** Then come back and press **E-mail sent manually**, so the step is closed. If the text is too long for your mail program, use **Copy text** and paste it.
- **E-mail sent manually**: registers the e-mail as sent now. A few minutes later, when the archive mailbox is read, the real message (with its exact text and date) replaces this entry, so the history does not show it twice. If you forgot to press the button, the real message still replaces the draft, as long as the subject is the same.
- **Delete this step**: removes the planned e-mail from the deal completely. Use it only if you no longer want that step; to come back to it later, use the link back to the deal instead.
- **Save draft**: keeps your changes for later.

The customer's reply is recognised by the same reference in the subject, so it is added to the deal when it reaches the archive mailbox. If a contact answers you by another way, ask whoever manages sequences to use **Mark replied** on the sequence so that it stops (it needs the sequence right).

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
| The customer replied but nothing is on the deal | The message did not reach the archive mailbox, or it is not in a thread already on the deal | Forward it to the archive address, from the thread |
| An error after **Link proposal** | The proposal is already linked to this deal | Nothing to do: it is already there |
| The linked proposal shows the wrong ref or total | The id is not checked when linking, so a mistyped id links another proposal | Unlink it and link the right id, read on the proposal's own page |
