# Retail POS — Roadmap and decisions log

The owner and Claude keep this file up to date. It answers three questions: what is
built, what comes next, and what was decided (and why) during the design conversations.

- **Design (source of truth):** [specs/2026-09-24-core-design.md](specs/2026-09-24-core-design.md), approved 2026-09-24
- **Implementation plans:** [plans/](plans/), one file per phase, each written only after the previous phase is finished

---

## Phase status

| # | Phase | Status | Plan |
|---|---|---|---|
| 1 | Foundation — login, users, roles & permissions, registers, settings, exchange rate, audit log | **Implemented** 2026-09-24 (9 tasks, 107 tests green) — awaiting the owner's browser check | [plans/2026-09-24-phase1-foundation.md](plans/2026-09-24-phase1-foundation.md) |
| 2 | Catalog — categories, products, units, barcodes, prices | Waiting for Phase 1 | — |
| 3 | Stock — movements, purchases, suppliers, cost | Waiting | — |
| 4 | POS + cash sessions — touch sale screen, payments, change, opening a session | Waiting | — |
| 5 | Printing — receipts, reprints, X/Z printouts | Waiting | — |
| 6 | Returns — normal + waste, voids, debt collection | Waiting | — |
| 7 | Expenses — expenses, cash in/out, supplier payments | Waiting | — |
| 8 | Reports — sales, payments, profit, stock, credit, stock table print | Waiting | — |
| 9 | Stocktaking — count, differences, adjustments, history | Waiting | — |
| 10 | Hardware testing, backups, LAN deployment | Waiting | — |

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
| 2026-09-24 | Permissions (Phase 1 review) | A user who is **not** an Admin cannot give the Admin role, cannot edit / reset the password of / set the PIN of an Admin account, cannot change their own role, and cannot edit the permissions of their own role — even if their role holds `user.manage` / `role.manage`. Decided by Claude from the review finding; **owner to confirm**. |
| 2026-09-24 | Sessions (Phase 1 review) | PHP sessions are stored in `storage/sessions/` (not XAMPP's shared `tmp`) and live 9 hours, so php.ini's 24-minute garbage collection cannot sign a quiet till out. A form posted after the session ended returns to the sign-in page with a message, never a 419 page; 419 is only for a signed-in user with a stale token. |
| 2026-09-24 | Web root (Phase 1 review) | A root `.htaccess` denies everything except `index.php`; `public/.htaccess` grants. `.git/`, `docs/`, `storage/` and any future folder are never served over the LAN. The dev install's admin account has `must_change_password = 1`, so the placeholder password is replaced at first sign-in. |
