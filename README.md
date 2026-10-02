# Any-Chart Reports Lab (prototype)

A **test module** for Dolibarr issue
[#31760](https://github.com/Dolibarr/dolibarr/issues/31760): a Balance Sheet and Income Statement
that work with **any chart of accounts**, not just one country's numbering.

It is a prototype to try a design on real charts and collect feedback before proposing it for
Dolibarr core. **It changes nothing in your accounting.** It only reads your ledger, and keeps its
own settings in its own tables.

> **Status: prototype (v0.2), for testing and feedback.** Not a finished module: please try it on a
> copy of your database or a test install first, and report problems on
> [#31760](https://github.com/Dolibarr/dolibarr/issues/31760) or in this repository's Issues.

## How it works

Every account gets a **nature**:

| Nature | Meaning |
|---|---|
| ASSET, LIABILITY, EQUITY | Balance sheet |
| INCOME, EXPENSE | Income statement |
| CLEARING | Suspense/clearing account: should be zero, flagged when it isn't |
| EXCLUDED | Totals, memo or off-balance accounts: never added up |
| (empty) | Unclassified: listed separately, never silently dropped |

Two optional extras per account:

- **Alternate nature**, used when the balance is on the other side. Example: a bank account is an
  ASSET, but a LIABILITY when overdrawn. GST/VAT accounts often need this too.
- **Contra**: the account stays on its nature's side and is shown as a deduction. Example:
  accumulated depreciation (an asset with a credit balance), sales returns.

## Try it

1. Requirements: Dolibarr 23 or later, Double-entry Accounting module enabled, a chart of accounts
   selected (Accounting > Setup).
2. Install it, either way:
   - **Zip** (easiest): download `module_anychartlab-x.y.zip` from this repository's
     [Releases](https://github.com/mike-wallis/dolibarr-anychartlab/releases) and upload it in
     Setup > Modules > **Deploy/install external app/module**.
   - **Git**: from `htdocs/custom/`, run
     `git clone https://github.com/mike-wallis/dolibarr-anychartlab.git anychartlab`
     (the folder must be called `anychartlab`).

   Then enable **Any-Chart Reports Lab** in Setup > Modules (section Financial).
3. Give yourself the two permissions (Users & Groups > your user > Permissions > Any-Chart Reports
   Lab).
4. Go to Accounting > **Any-Chart Reports Lab > Setup** (the setup pages are listed under it once
   clicked) > **Account natures**. On first use the module looks at
   your company country (Setup > Company) and your chart, and suggests a rule set and layouts:
   click **Apply suggestions**. Or pick a **Rule set** and click **Apply default natures**. Rules
   come from the chosen rule set, then `seed/generic.csv`, then the parent account.
5. Open **Setup > Classification check** and fix what it lists: unclassified accounts with a balance,
   clearing accounts with a balance, accounts whose balance is on the wrong side (often a missing
   contra flag or alternate nature).
6. Open the **Balance Sheet** and **Income Statement**. The Balance Sheet checks that
   Assets = Liabilities + Equity and explains any difference. Options: Detailed / Summary view,
   *Show entries* (ledger entries under each account), *Hide empty lines* (with a layout), and
   export as **CSV**, **PDF** or **Preview PDF**.

   The Income Statement can also show **several columns**: *Columns* = Months, Quarters (fiscal)
   or Fiscal years across the date range, with a Total column (at most 13 periods; `*` marks a
   part period), or *Compare with* = Previous period / Same period last year, with Change and
   Change % columns. Quarters and years follow the fiscal year start month (Setup > Company).
   Works by nature and with layouts, on screen, in CSV and in PDF (landscape).

   The Balance Sheet has the same options for **dates**: *Columns* = Month-ends, Quarter-ends
   (fiscal) or Fiscal year-ends from a start date up to the "As of" date (each column has its own
   balance check line), or *Compare with* = End of previous month / End of last fiscal year / Same
   date last year, with Change and Change % columns.

## Trial Balance

Accounting > Any-Chart Reports Lab > **Trial Balance**: every account with a balance or a movement
in the period: opening balance, debits, credits and closing balance (debit / credit columns), with
its nature. In account order, or **grouped by nature** with subtotals. Entries come from the same
window as the Balance Sheet (after the last closed fiscal year), so the closing balances are the
Balance Sheet at the end date. Checks: debits = credits for the period, closing debit balances =
closing credit balances. Accounts with no nature are flagged (they are in the Trial Balance but left
out of the statements). CSV / PDF (landscape).

## Cash Flow statement

Accounting > Any-Chart Reports Lab > **Cash Flow** (indirect method): the profit for the period,
then the movement of every other balance sheet account in its **cash flow class**: operating
(working capital; non-cash items such as depreciation are added back), investing (fixed assets) or
financing (loans, equity). **Cash** = the accounting accounts of Dolibarr's bank and cash accounts
(Banks / Cash), plus any account set to "Cash". Opening cash + net cash flow is checked against the
cash accounts at the end. Opening-balance entries dated in the period (journals of type "opening",
bank accounts' initial balances) count as opening balances, not cash flows.

Each account's class is suggested automatically (bank accounts; equity = financing; labels such as
loan, lease, overdraft = financing; vehicle, equipment, "at cost", goodwill = investing; depreciation
= operating; French PCG by class number) and can be changed in the **Cash flow** column of Account
natures. The class is included in the mapping export / import (`cf_class`). Accounts with no nature
are shown in their own section, so the statement still adds up.

If you installed an earlier copy of the module, **disable and enable it once** (Setup > Modules):
that adds the new column to its table. Nothing else is changed.

## Accounts Receivable / Accounts Payable (from the ledger)

Accounting > Any-Chart Reports Lab > **Accounts Receivable** / **Accounts Payable**: what each
customer owes / what is owed to each supplier, from the accounting ledger (entries on the customer
/ supplier control account set in Accounting > Setup, per subledger account). Open items use
Dolibarr lettering where entries are lettered; otherwise payments and credit notes are matched to
the oldest open invoices of the same third party, and leftover payments are shown as unallocated.
Aged by due date or document date, as of any date, oldest first (90+ ... not yet due; Display
can switch the order), Summary (one line per third party) or Detailed (each open invoice / payment
with its date and due date), CSV / PDF (landscape). Two checks: **1.** the report total equals the control account balance (as on the
Balance Sheet); **2.** per third party, ledger vs Dolibarr's unpaid invoices (as of today), which
shows invoices or payments not transferred to accounting or not matched in Dolibarr.

**Source = Unpaid invoices** builds the same report from Dolibarr's invoices instead of the ledger:
what was still owing on each validated invoice at the date (total, less payments dated up to the
date and credit notes / deposits applied; invoices closed by the date owe nothing), plus unused
credit notes and available credits shown as credits. Checked against Dolibarr's "remaining to pay".
It is the **default with cash accounting** (Accounting > Setup > Cash accounting): in that mode
Dolibarr transfers payments straight to income and expense accounts, so the ledger has no
receivables or payables. The other reports read the ledger and work in both modes (with cash
accounting the Income Statement is a cash-basis result and the Balance Sheet has no trade debtors /
creditors).

## Layouts (country presentation)

Reports can be shown **by nature** (assets / liabilities / equity, income / expenses) or through a
**layout**: the lines your country uses, e.g. current / non-current assets, gross profit.
Accounting > Any-Chart Reports Lab > Setup > **Layouts**:

- **Load a layout file**: samples in `seed/layouts/`: Australia (AU-BASE numbering) and France
  (PCG: Bilan, Compte de résultat). Files suggested for your chart or country are marked ★.
- **Copy a Dolibarr personalised report**: reads Accounting > Setup > Personalised groups and makes
  a lab copy. **Your personalised groups are never changed.**
- **Import a layout CSV**, or start from an **empty layout**.
- Edit it on screen: lines are *headings*, *groups* (take accounts by nature and/or account-number
  prefixes such as `11,12,!1301,512D`, or by explicitly picked accounts) and *formulas* (e.g.
  `REVENUE-COGS`; on the Balance Sheet `RESULT` is the result of unclosed periods). The editor checks
  the layout against your chart: lines that take no account, accounts no line takes, unknown codes.
- Choose it on the Balance Sheet / Income Statement with the **Layout** selector. Accounts no line
  takes are listed under "Not in layout", never dropped.
- **Export** it to share your country's layout.

## Display settings

Accounting > Any-Chart Reports Lab > Setup > **Display**: PDF paper size, orientation and margins; font,
font size, title size and indent; heading (company name, a custom heading text, logo, period line);
footer (text, printed date, page numbers); which PDF columns to show; negative amounts as -1,234.56
or (1,234.56); 2 decimals or whole units; which view / options a report opens with; and
**line styles** for each kind of line (headings, group titles, parent accounts, account lines,
sub-accounts, ledger entries, subtotals, group totals, formulas): font, size, bold, italic, upper
case, indent, rule above, shading and colour, on screen and in PDF. **Reset to defaults** restores
everything.

## Please send feedback

Once the natures are right for your chart, click **Export mapping (CSV)** on the Account natures
page and post the file on [#31760](https://github.com/Dolibarr/dolibarr/issues/31760), with your
country and chart code. Those files become the default rules for everyone using that chart.

Also useful: anything that's wrong or awkward for your country's statements, and whether the
alternate nature and contra flag fit your cases better than the draft's `BIFUNCTIONAL` /
`CONTRA_ASSET` codes. Tick **Show design-draft codes** on any page to compare.

## Rule files (`seed/`)

Semicolon-separated, first match wins, `#` lines are comments:

```
pcg_type;account_prefix;label_contains;nature;nature_alt;contra;comment
ASSET;1301|1321|1331;;ASSET;;1;Accumulated depreciation: contra-asset
```

Each match column can hold several values separated by `|`. An empty match column means "any".

## Limits (prototype)

- English only.
- Module number `500023` is in Dolibarr's range for private modules: fine for testing, an official
  number would be reserved if this ever became a published module.
- Rule sets: France (PCG: PCG25-DEV, PCG18-ASSOC, PCGAFR14-DEV, PCG99 New Caledonia), Australia
  (AU-BASE), United States (US-BASE, US-GAAP-BASIC), plus generic rules. Layouts: France and
  Australia. The French files need checking by French accountants.
- `seed/catalog.csv` lists which country / charts each file suits. Country is only used for
  countries with a national numbering standard (e.g. France).
- The Balance Sheet window starts after the last **closed** fiscal year. It assumes fiscal years
  are closed in order and warns if they aren't.

Licence: GPL v3 or later. Published by Dolibarr User Australia.
