# Retail POS — Roadmap and decisions log

The owner and Claude keep this file up to date. It answers three questions: what is
built, what comes next, and what was decided (and why) during the design conversations.

- **Design (source of truth):** [specs/2026-09-24-core-design.md](specs/2026-09-24-core-design.md), approved 2026-09-24
- **Implementation plans:** [plans/](plans/), one file per phase, each written only after the previous phase is finished

---

## Phase status

| # | Phase | Status | Plan |
|---|---|---|---|
| 1 | Foundation — login, users, roles & permissions, registers, settings, exchange rate, audit log | **Done** 2026-09-24 (browser-checked by the owner; 115 tests) | [plans/2026-09-24-phase1-foundation.md](plans/2026-09-24-phase1-foundation.md) |
| 2 | Catalog — categories, products, units, barcodes, prices | **Implemented** 2026-09-24 (8 tasks + review fixes, 180 tests) | [plans/2026-09-24-phase2-catalog.md](plans/2026-09-24-phase2-catalog.md) |
| 3 | Stock — movements, purchases, suppliers, cost | **Implemented** 2026-09-24 (continuous build) | — |
| 4 | POS + cash sessions — touch sale screen, payments, change, opening a session | **Implemented** 2026-09-24 | — |
| 5 | Printing — receipts, reprints, X/Z printouts | **Implemented** 2026-09-24 (HTML receipts, Chrome kiosk printing) | — |
| 6 | Returns — normal + waste, voids, debt collection | **Implemented** 2026-09-24 | — |
| 7 | Expenses — expenses, cash in/out, supplier payments | **Implemented** 2026-09-24 | — |
| 8 | Reports — sales, payments, profit, stock, credit, stock table print | **Implemented** 2026-09-24 | — |
| 9 | Stocktaking — count, differences, adjustments, history | **Implemented** 2026-09-24 | — |
| 10 | Hardware testing, backups, LAN deployment | Backups work and the **restore drill passed** 2026-09-28 (`bin/restore-drill.php`); hardware and LAN tests are the owner's | — |

**Status on 2026-09-29:** every phase is built and covered by 237 automated tests. Phases 2–9 stay
"Implemented" until the owner has checked them in the browser; only then "Done".

### Open items

| # | What | Whose |
|---|---|---|
| 1 | **Restart Apache** so the Backups page sees PHP's zip extension (enabled in `php.ini` on 2026-09-28; the command line already works). | Owner |
| 2 | Schedule the nightly backup in Windows Task Scheduler and copy the zips off this PC (USB or another machine). | Owner |
| 3 | Test the receipt printer, cash drawer, barcode scanner and Chrome `--kiosk-printing`. | Owner |
| 4 | Test a second PC on the LAN as a till. | Owner |
| 5 | Set the administrator's **approval PIN** (none yet, so no cashier discount can be approved) and change the password `admin123`. | Owner |
| 6 | Before opening: `config/app.ini` with `env = production` so error pages stop showing technical details. | Owner + Claude |
| 7 | Click through in a browser: purchases, stocktaking, returns, closing a session (blind count), hold / resume, debt collection. Their rules are tested, their screens were not driven. | Claude |
| 8 | The two existing "Ahmad Saleh" customers: rename or merge by hand (new duplicates are refused; there is no merge tool). | Owner |
| 9 | Two products keep a unit typed by hand (`Loose 100g`, `Cube`). They work and are shown "as it is"; pick a type for them on the product page when convenient. | Owner |
| 10 | At closing, a surplus is shown in red like a shortage. | Claude, when asked |
| 11 | The old per-part product addresses (`products/unit-store`, `products/prices`, `products/cost`…) still answer but no page uses them; remove them together with the tests that call them. | Claude, when asked |
| 12 | "A lot of settings are missing" (owner, 2026-09-24): which ones was never said. Only the logo was added. | Owner to list |

**Tools:** `php bin/seed-demo.php` fills an empty database with demo data; `php bin/reset-data.php` takes a backup,
empties the shop (users, roles, registers, settings and the rate stay) and seeds again, `--keep-products` keeps the
catalogue, `--no-seed` leaves it empty; `php bin/restore-drill.php` proves that a backup restores (run it monthly).
**Workflow for every phase:** Claude asks the phase's "Decide before the plan" questions
and writes the plan from the spec and the existing code → the owner reviews it →
implement task by task (tests first, every task ends green and committed) → the owner
checks it in the browser → mark it done here → next phase.

**Why plans are written one phase at a time:** each plan uses the exact class names,
functions and tables created by the phases before it. Writing all plans up front
would mean guessing code that does not exist yet, and every small change in an early
phase would silently break the later plans. The scope and "done when" of every phase
are fixed below, so nothing is lost by waiting.

---

## Scope and "done when" per phase

Section numbers (§) refer to the design spec.

### Phase 2 — Catalog (§3.3, §4, §5)
- Categories (name, colour for the touch tile, order), products (name in Arabic OK,
  internal code for items without barcode, base unit piece / g / ml), product units
  with a conversion factor (Box = 20,000 g, Dozen = 12 pieces…), several barcodes per
  unit, retail and wholesale price per unit, cost per base unit, margin display.
- Product list with filters (name, barcode, category, unit, stock status, price) and
  a printable product/stock table.
- **Done when:** charcoal can be defined as g + kg + Box with its own prices; a
  product without a barcode is findable by internal code; cashiers never see cost or margin.
- **Decide before the plan:** product images yes/no; the exact list of units offered by default.

### Phase 3 — Stock (§3.4, §3.6, §5, §11)
- `StockService` as the only way to change stock, stock movement history,
  opening stock, purchases from suppliers (supplier optional), moving-average cost
  update on purchase, supplier ledger, adjustments and waste with reasons.
- **Done when:** "5 @ $10 then 5 @ $15" gives a $12.50 cost; stock shows as
  "9 Box + 17.5 kg"; every change has a movement row with user and reason.

### Phase 4 — POS and cash sessions (§6, §7, §8.1, §10, §15)
- Session open (opening USD + LBP) per register, touch sale screen, barcode scanner
  capture (fast keystrokes + Enter, even when the search box is not focused), product
  grid by category, cart, sell by quantity or by amount (charcoal), retail/wholesale
  level, discounts with the Admin PIN override, mixed payments (USD, LBP, card, credit),
  change currency choice, 5,000 LBP rounding of change and of the LBP amount due,
  invoice numbers from `counters`, oversell warning, customers and credit.
- **Done when:** "$23 = $20 + 270,000 LBP" is fully paid; "$50 paid for $23.20 with
  change in LBP" gives 2,410,000 LBP and +$0.02 rounding; cash movements per currency
  are exact; a cashier cannot sell without an open session on a registered device.
- **Decide before the plan:** POS screen layout (a separate short screen spec with a
  mockup), and whether "hold / park a sale" is included.

### Phase 5 — Printing (§2.1)
- HTML receipt template from settings (header, footer, shop details), reprints marked
  COPY, X and Z report printouts, 80 mm (and 58 mm) layouts.
- **Decide before the plan:** Chrome `--kiosk-printing` (no extra software) or a
  C# + WebView2 terminal app (drawer opens on command, locked full screen). The
  owner deferred this choice to this phase.

### Phase 6 — Returns (§9, §13)
- Returns linked to the invoice, restock vs waste per item, discount-proportional
  refund values, debt reduction first on credit invoices, refund from the current
  session's drawer, voids (same session only), debt collection at the till.

### Phase 7 — Expenses (§3.6, §11)
- Expenses paid from the drawer (cash movement) or from outside, supplier payments,
  cash in / cash out with reasons.

### Phase 8 — Reports (§16)
- Sales (day/week/month/range, by cashier, by product, retail vs wholesale), payments
  per method **and** per original currency, credit outstanding, profit (gross sales →
  COGS → waste → gross profit → expenses → net profit), stock, movements, purchases,
  returns, waste, session history; printable and exportable.

### Phase 9 — Stocktaking (§12)
- Count by all products or by category, entry by unit ("3 Box + 4.2 kg"),
  differences applied to the current stock so sales during the count are not lost,
  history with who and when.

### Phase 10 — Real-world readiness
- Test with the real scanner, printer and cash drawer, two terminals on the LAN,
  automatic daily backup and restore, `config/app.ini` production mode, and the
  owner's manual test list.
- **Backup = one zip of the SQL dump + the `public/uploads/` folder** (product images are
  files, not database rows — decided 2026-09-24); restore = unzip + import the SQL.

---

## Decisions log

Everything below was decided in the design conversations of 2026-09-23 and 2026-09-24.
Newest decisions go at the bottom. Never delete an entry; add a new one that overrides it.

| Date | Topic | Decision |
|---|---|---|
| 2026-09-24 | Business | Argile / Hookah shop in Lebanon. New system from zero; no repair features. Reuse the Daher Phone style (plain PHP micro-MVC, XAMPP) plus a Services layer. |
| 2026-09-24 | Location / name | Project **Retail POS** at `C:\xampp\htdocs\Retail POS`. |
| 2026-09-24 | Prices | All product prices (cost, retail, wholesale) are stored in **USD** only. |
| 2026-09-24 | Payments | USD cash, LBP cash, USD + LBP on one invoice, card, credit, and combinations. The rate used is stored on every transaction. |
| 2026-09-24 | Exchange rate | **One** rate (no buy/sell pair), set by the Admin, full history kept. Example 1 USD = 90,000 LBP. |
| 2026-09-24 | LBP rounding | Change rounds to the nearest **5,000 LBP**; the difference is recorded (`rounding_usd`). The LBP amount due is shown rounded too; a leftover of ≤ 2,500 LBP counts as paid. |
| 2026-09-24 | Costing | Keep simple: **one cost price per product** (no FIFO layers). Cost is frozen on every sale line; purchase costs are never overwritten. |
| 2026-09-24 | Q1 cost update | On purchase, cost updates by **moving average**. |
| 2026-09-24 | Weight products | Required from day one: charcoal stored in grams, sold by g/kg/box, box has its own price. Stock is always an integer of base units. |
| 2026-09-24 | Refunds | Kept simple: USD value of the returned items, paid out at the **current** rate. |
| 2026-09-24 | VAT / TVA | **Not included.** |
| 2026-09-24 | Expiry / batches | **Not included.** |
| 2026-09-24 | Users | One main Admin + POS users (cashiers); permission system with keys so roles can be added later. |
| 2026-09-24 | UI language | Interface **English only**. User-entered data (products, customers, suppliers) may be **Arabic**. |
| 2026-09-24 | Suppliers | Records, purchases, purchase history; balances via a supplier ledger; kept simple in v1. |
| 2026-09-24 | Expenses | Included. Expenses paid from the drawer are cash movements in the X/Z reconciliation. |
| 2026-09-24 | Terminals | One server on the LAN, one or more POS terminals. **Apache**, not the PHP built-in server. |
| 2026-09-24 | Q2 overselling | Allowed **with a warning**, listed for Admin review. |
| 2026-09-24 | Q3 rounding tie | An exact 2,500 LBP tie rounds **up** (customer-friendly). |
| 2026-09-24 | Q4 card | Card payments are always recorded in **USD**. |
| 2026-09-24 | Q5 discounts | Cashiers may discount **0 %** without the Admin PIN; the Admin can raise the limit in settings. |
| 2026-09-24 | Q6 credit limit | Optional per customer; empty = no limit. |
| 2026-09-24 | Cash closing | Blind count: the cashier counts cash by denomination **before** seeing the expected amount. Expected vs actual per currency (USD and LBP separately). Differences are never edited; corrections are logged adjustment entries. The Admin reviews every Z and can force-close forgotten sessions. |
| 2026-09-24 | Records | Financial records are append-only: voids, returns and adjustments are new rows, never edits or deletes. Invoice numbers come from locked counters (no gaps, no duplicates). |
| 2026-09-24 | Hardware | USB barcode scanner (keyboard mode) + receipt printer with a cash drawer attached. **No scale.** |
| 2026-09-24 | Desktop app | Not now. It stays a web system; a C# + WebView2 "terminal app" is the upgrade path if direct drawer control is ever needed. |
| 2026-09-24 | Printing | **Deferred to Phase 5**: Chrome kiosk printing vs the terminal app. Receipts are built as HTML templates so both work. |
| 2026-09-24 | Spec | Core design spec approved by the owner. |
| 2026-09-24 | Execution | Phase 1 plan written. Execution method not chosen yet: **Native** (recommended) or subagent-driven. |
| 2026-09-24 | Execution | Owner chose **Native** (Claude executes the plan inline, one fresh-context review of the whole phase at the end). Phase 1 implemented the same day on `main` in the project folder (no worktree: Apache serves this exact path). |
| 2026-09-24 | Permissions | `audit.view` (Admin group) added as the 39th permission key so the audit log viewer has its own key; spec §15 calls its list an "initial set". |
| 2026-09-24 | Permissions (Phase 1 review) | A user who is **not** an Admin cannot give the Admin role, cannot edit / reset the password of / set the PIN of an Admin account, cannot change their own role, and cannot edit the permissions of their own role — even if their role holds `user.manage` / `role.manage`. Decided by Claude from the review finding; **confirmed by the owner 2026-09-24**. |
| 2026-09-24 | Sessions (Phase 1 review) | PHP sessions are stored in `storage/sessions/` (not XAMPP's shared `tmp`) and live 9 hours, so php.ini's 24-minute garbage collection cannot sign a quiet till out. A form posted after the session ended returns to the sign-in page with a message, never a 419 page; 419 is only for a signed-in user with a stale token. Confirmed by the owner 2026-09-24. |
| 2026-09-24 | Web root (Phase 1 review) | A root `.htaccess` denies everything except `index.php`; `public/.htaccess` grants. `.git/`, `docs/`, `storage/` and any future folder are never served over the LAN. The dev install's admin account has `must_change_password = 1`, so the placeholder password is replaced at first sign-in. |
| 2026-09-24 | Phase 2: images | **Yes, optional**: one photo per product (jpg/png, resized server-side, stored under `storage/`), shown on the POS tile and the product form. |
| 2026-09-24 | Phase 2: units | Default unit names offered when adding a product: **Piece, Pack, Box, Carton, Dozen, kg, g, L, ml**. The admin may type any other name. Base unit is always piece, g or ml. |
| 2026-09-24 | Phase 2: internal codes | **Auto-generated and editable**: `P-000001`… from the `counters` table (`product`); the admin may overwrite it (unique). Every product is findable by code at the POS. |
| 2026-09-24 | Phase 2: image storage & backup | Product images are **files** in `public/uploads/products/` (served directly by Apache, PHP execution denied there), not blobs in the database and not under `storage/` as first written. The daily backup (Phase 10) therefore zips the SQL dump **and** `public/uploads/`. |
| 2026-09-24 | PHP config | `extension=gd` enabled in `C:\xampp\php\php.ini` (one line; was commented out) for product-image resizing. Apache must be restarted once for the web side. |
| 2026-09-24 | Execution | Phase 2 plan approved; executed **Native** (same as Phase 1). |
| 2026-09-24 | Process | Owner switched to **one continuous build of Phases 3–9**: no per-task test cycles, one full test run at the end, fewer tests (key business rules only), plus a UI redesign. Replaces the phase-by-phase workflow in CLAUDE.md for the rest of v1. |
| 2026-09-24 | Printing | **Chrome `--kiosk-printing`**: receipts and X/Z reports are HTML pages printed silently to the Windows default printer; the drawer opens via the printer driver's "open drawer on print" setting. |
| 2026-09-24 | POS screen | Grid left (category tabs, product tiles, scan/search box), cart right (lines, USD + LBP totals, Pay). **Hold / park a sale: yes**, resumed by name. |
| 2026-09-24 | Prices | A price of **0 means "not sold at this level"** (same as empty). |
| 2026-09-24 | Units | A unit's **factor locks once the product has stock movements**; add a new unit instead. |
| 2026-09-24 | Cash closing | **Only the admin closes sessions** (migration 004 removes `session.close_own` from the Cashier role). The cashier signs out; the admin counts the drawer and closes from Cash sessions. The Registers page shows who is using each register. |
| 2026-09-24 | UI | Redesign: clean light admin (warm neutral palette, one accent, system font stack, roomy cards) and a high-contrast dark touch POS with 56 px targets. |
| 2026-09-25 | Logo | Settings → Logo: an **SVG** (kept as vector after a safety check) or PNG/JPG/WEBP/GIF (resized to fit 600×300). Shown on the menu, the sign-in page and at the top of **every printout**: receipts, X/Z reports, printed reports. It fills its frame; the shop name stays beside it. The file lives in `public/uploads/`, so it is in the backups. |
| 2026-09-25 | Assets | The app's own CSS/JS links carry `?v=<file time>`, so the terminals' browsers pick up every update without Ctrl+F5. |
| 2026-09-25 | Demo data | `bin/seed-demo.php` creates an argile shop to test with (20 products, suppliers, customers, two cashiers, a purchase, an open session with sales). It refuses production and non-empty databases without `--force`. |
| 2026-09-26 | Units | **No free text.** A unit is picked from a list of types: Piece, Dozen, g, kg, ml, L, Pack, Box, Carton, Case, Bag, Roll, Bottle, Can, Set. Plain measures fix their factor (kg = 1000 g, Dozen = 12); containers ask how many base units they hold. The name is composed: "Box of 6", "Pack 250g", "Box 1kg". Only types that fit the base unit are offered. Two sizes of one type on a product are allowed. |
| 2026-09-26 | Box and piece | "A box holds 6 pieces, sold by piece or by box" = base unit *piece*, unit Piece (factor 1) and unit Box (factor 6), each with its own price. Stock is kept in pieces; selling a box takes 6. |
| 2026-09-26 | Process | When the owner asks "how to solve X?" or questions a design, Claude **answers and offers options first** and edits nothing until the owner chooses. A plain instruction is implemented directly. |
| 2026-09-28 | Approval PIN | **Only administrators have a PIN.** The PIN card does not show for other users and the service refuses one. The till asks for the PIN **as soon as** a cashier enters something restricted, not at the end; without it the discount is not applied. One approval covers the rest of that sale. Wholesale prices, credit and price changes use the same dialog. |
| 2026-09-28 | Approval PIN field | It is a masked text field with `autocomplete="one-time-code"`, not a password field, so browsers never offer to save or fill it. The eye that shows passwords is not on the PIN. |
| 2026-09-28 | Discounts | Line and invoice discounts are typed as a **percentage** (default) or in USD, with the other value shown underneath. The pre-filled payment follows the invoice discount. |
| 2026-09-28 | Below cost | A line that ends under its cost after line discount, invoice discount or a changed price is flagged at the till, on the sale's result and in the audit log (`sale.below_cost`). The till is told yes or no per line, **never the cost**. It **needs the administrator's PIN** for anyone who is not an administrator, whatever the allowed percentage. A list price already under cost only warns, because the cashier lowered nothing. |
| 2026-09-28 | Navigation | Every page except the dashboard has a small **Back**: a page inside a section goes to the section, a section page with filters goes to the section without them, a plain section page goes to the dashboard. Password fields have an eye. |
| 2026-09-28 | Registers | A device whose register has an open session **cannot move** to another register. Taking over a register in use from another device stays possible (a broken PC) and says whose shift continues. A register with an open session cannot be deactivated. A deactivated register can be **reactivated**. |
| 2026-09-28 | Busy register | One register, one open session. While somebody else's session is open, the dashboard says "In use by …". The administrator gets "Count and close S-…"; a cashier is told to ask the administrator and sees no button to a page they may not open. |
| 2026-09-28 | Customers | A **phone** that another customer has is refused, however it is written (`03 111 222` = `+961 3 111 222`). The same **name** asks for "different person, save anyway". Editing checks only what was changed. |
| 2026-09-28 | Sales list | Invoices with returns carry a badge: **returned** or **part returned**. Walk-in returns say "Walk-in" in the Returns report. |
| 2026-09-28 | Profit report (B1) | Fixed: refunds and returned cost are two separate sums. Joined, a return was counted once per item. |
| 2026-09-28 | LBP typing | LBP amounts show thousands separators while they are typed (`10,000,000`), in the admin pages and at the till. The server reads LBP with or without them. |
| 2026-09-28 | Backups | PHP's **zip extension must be on** (`extension=zip` in `php.ini`); it was off, so no backup had ever worked. The Backups page and the command now say so. First **restore drill passed** on the owner's data: 35 tables and 666 rows identical, money figures and Arabic names identical, uploaded files identical. `bin/restore-drill.php` repeats it; run it monthly. |
| 2026-09-28 | Till sign-in | The sign-in ends after **8 hours without activity** or when the browser closes; the device link lasts 400 days. An open cash session stays enterable by its owner until it is counted and closed. A till whose sign-in ended says "You were signed out"; the sale in progress is kept in that browser per cash session. Only the owner of a session sells in it (one drawer, one person): the administrator does not enter a cashier's session. |
| 2026-09-29 | Product page | **Rebuilt: one page for adding and editing, one Save.** A row is one way the product is sold, with its prices and barcodes. Owner's choice over "only fix the errors": the old page had about ten save buttons, each saving its own part and dropping what else was typed. |
| 2026-09-29 | Product page | "Base unit" is asked as **How is it sold? By piece / by weight / by volume**, and cannot change once the product exists. A box's weight is typed as `20 kg`, not 20,000 g. Names from a thousand up read in kg or L ("Bag 1.5kg"). |
| 2026-09-29 | Fractions | Decided by the unit, not by a tick box: **only kg and L are sold in parts**. Half a piece or half a box cannot be sold. The old tick on a piece charged half and took a whole piece from stock. |
| 2026-09-29 | Units | With stock history a unit's size is locked (shown with a lock). A unit that was sold or purchased stays on the product; emptying its prices stops it being sold. A unit **typed by hand before the list existed is left exactly as it is** unless the owner picks a type for it. |
| 2026-09-29 | Units | Every unit counts in the stock display ("9 Box 20kg + 17.5 kg"); the till shows the unit marked "Till"; purchases start with the largest unit. Cost, opening stock and minimum are typed "per" a unit and follow the **largest unit** until the owner picks another. |
| 2026-09-29 | Delete a product | Allowed only when it was **never sold, purchased, counted or moved in stock**; otherwise it is switched off (Active). |
| 2026-09-29 | Same product name | **Warn and allow**, as with customers: a tick box "Save with the same name" after the first attempt. Saving a product under its own name asks nothing. |
| 2026-09-29 | Product list | One search that works while typing (name, code or a scanned barcode), category and stock. A row opens its product. "Print this list" prints what the filters show. Cost reads per piece, kg or L with two decimals. |
| 2026-10-03 | Customers list | The column "Limit" is called **"Credit limit"** (customers list and credit report). Asked by Aya. |
| 2026-10-03 | Till done screen (U10) | A sale is never shown as "Paid … exactly" unless cash covered it. **On credit:** amber notebook, "On credit" with the amount, and "<customer> now owes $X (limit $Y)". **Part cash / card + credit:** "Paid $5.00 cash · $4.50 on credit". **Card only:** "Paid $X by card · No cash". Cash sales are unchanged. The receipt of a credit sale prints "*** ON CREDIT ***", the amount on credit, the **balance owed right after that sale** (a reprint keeps that day's balance) and a customer signature line. Asked by Aya. |
| 2026-10-03 | Refund rounding (I4) | A cash refund in LBP is rounded to **5,000** like change, and the difference is **recorded on the return** (`returns.rounding_usd`, migration 005; same sign as `sales.rounding_usd`: + = the shop kept it). RTN-000001: $7.24 refunded as 650,000 LBP ($7.22) → **+$0.02**. The Profit report and the X/Z "Rounding" line include it, so reports and drawers agree to the cent; the return page shows it under "How it was refunded". Older returns were filled in from their refunds. Asked by Aya. |
| 2026-10-03 | Till payment box (U10) | "Change" only shows when **cash** was overpaid. **Credit:** "On credit: $95.00" in amber, with "<customer> will owe $110.00"; red with "over the $50.00 limit: administrator PIN needed" when the limit is passed, and "Choose a customer to sell on credit" when none is chosen, all **before** Complete. **Card:** "By card: $9.50 · No change". **Cash + card / credit:** "$5.00 cash + $4.50 on credit". Exact cash: "Exact amount · no change". Change under $1 in USD mode no longer reads "$0.00 + …". Asked by Aya. |
| 2026-10-03 | Drawer cannot go negative (I5) | **Option A, warn and allow** (chosen by Aya): change or a cash refund that needs more of a currency than the drawer holds (Expected, plus cash received in the same sale) is stopped with "The drawer has only 10,000 LBP: 650,000 LBP of refund cannot come out of it. Give it in USD, record a Cash in first, or continue if you are adding the money yourself." The cashier can continue on purpose (till: "Continue: I add the money myself"; returns: a tick box); that is written to the audit log as `drawer.short`. Not a wall, so a wrong opening count never blocks a customer. Seen on S-000004 (10,000 LBP, a 650,000 LBP refund, Expected −640,000). |
| 2026-10-04 | Expenses in two currencies | One expense or supplier payment can be paid **partly in USD and partly in LBP** ("$10 + 450,000 LBP"): the form has an Amount in USD and an Amount in LBP, fill one or both. Stored as `usd_paid` + `lbp_paid` (migration 006; the old single amount/currency moved into them) with `amount_usd` the total at the expense's rate. From the drawer, each currency is its own cash movement; a supplier payment is one ledger line for the total. Asked by Aya. |
| 2026-10-04 | Expense or supplier payment | The expense form starts with **"What is it? Expense / Supplier payment"**. An **expense** needs a category and never touches a supplier (a supplier left selected is ignored). A **supplier payment** needs a supplier and **no category**: it is saved as "Supplier payment", the amount is pre-filled with what we owe (change it for part), and the button reads "Pay supplier". "Pay supplier" on a supplier's page opens this form on that supplier. The list shows every supplier payment as "Supplier payment" with the supplier's name (older ones whose category was typed by hand, like ".", read the same; their stored category is unchanged). Asked by Aya. |
| 2026-10-04 | Refund in two currencies | The returns form has **two amounts, like the expenses form: "Give back in USD" and "Give back in LBP"** (no currency list). A live line shows "Refund $9.50 · debt reduced first … · give back in cash $9.50". By default it is all in USD; typing in one amount fills the other with the rest (LBP rounded to 5,000). One amount alone: the rest goes in the other currency; both: they must add up to the cash part, give or take half a 5,000 note, and what the rounding leaves is recorded on the return ($9.50 → $5.00 + 405,000 LBP; $7.24 → 650,000 LBP, +$0.02). Debt is still reduced first. Each currency is its own refund line and cash movement, and the short-drawer warning checks both. Asked by Aya. |
| 2026-10-04 | Debt collected at the till | Paid in **USD, LBP or both** ("Received in USD" + "Received in LBP"). A line says what happens before Collect: "Pays $5.00 · still owes $3.00", "Pays it all · no change", or "Pays $8.00 · Change: 180,000 LBP". More than owed: the debt is cleared and the rest is **change**, in LBP (rounded to 5,000) or USD (whole dollars, cents in LBP), chosen in "Give change in", which appears only then; within half a 5,000 note counts as paid in full. One ledger payment line (the amount as typed when one currency); one cash movement per currency in, and the change out. Change the drawer cannot give gets the short-drawer warning. After Collect the customer list shows the new balance at once (U12). Asked by Aya. |
| 2026-10-04 | Returns in the till | The till's **Returns** button opens a **pop-up** instead of the returns page. A cashier first types the **administrator's PIN** (the Cashier role no longer holds `return.create`, migration 007; spec §15 PIN override; a role given that permission in Roles needs no PIN, and the administrator never does). Then the sale is found by **customer name, item, phone or invoice number** (spec §9; a return stays linked to its invoice), the items, quantities and condition are chosen, and the money goes back in USD, LBP or both like the returns page. The PIN gives a 15-minute pass for searching; **saving checks the PIN again** on the server, and the approval is audited (`pin.override`, entity return). The return receipt can be printed from the till by anyone who uses it. Asked by Aya. |
| 2026-10-05 | Voids removed | Decided by Aya: **there is no void any more; a sale is undone with a return** (a till return for a cashier needs the administrator's PIN). The "Void this sale" box, its route, `SaleService::void` and the `sale.void` permission are gone (migration 008 removes it from every role and from the list: 38 permissions). Sales **voided before** keep their status and stay readable — "voided" in the sales list and on the sale page, "VOIDED" on a reprint, the voided count on X/Z, the "sale void" stock movements — because records are never edited or deleted. Overrides spec §13 and the 2026-09-24 "voids only in the same session" rule. |
| 2026-10-07 | Return fully taken by the debt | When the customer owes at least the refund (owes $8, returns $8), **no cash is given**: the "Give back in USD / LBP" boxes are **locked** on the Returns page and in the till pop-up, and the line reads "Refund $8.00 · nothing to give back in cash: the $8.00 comes off the customer's debt". When there is cash to give (refund $8, type $6), the other box **fills itself with the rest (180,000 LBP) and stays editable** — kept as it was. |
