# Retail POS — Core Design Specification (v1 draft for review)

**Date:** 2026-09-24 · **Status:** APPROVED by owner 2026-09-24 (Q1–Q6 defaults confirmed) · **Location:** `C:\xampp\htdocs\Retail POS`

This document is the architecture, the core database, and the business rules that
every later module depends on. It follows the confirmed business decisions of
2026-09-24. Screens (POS layout, reports, print templates) get their own
specs later; they are mentioned here only where the data model must support them.

Business: Argile / Hookah shop, Lebanon. Prices in USD, payment in USD and/or LBP.

---

## 1. Confirmed decisions this design implements

| Area | Decision |
|---|---|
| Product prices | USD only (cost, retail, wholesale). No stored LBP prices. |
| Payment | USD cash, LBP cash, USD + LBP mixed, card, credit, and combinations |
| Exchange rate | One rate, set by Admin, snapshotted on every transaction |
| LBP change | Rounded to nearest 5,000 LBP; rounding recorded |
| Costing | One cost price per product (no batches/layers). Cost frozen on every sale line |
| Weight products | Required from day one (charcoal sold by g/kg/box) |
| VAT, expiry, batches | Not in scope |
| UI language | English; user-entered data may be Arabic (utf8mb4 everywhere) |
| Users | One main Admin + POS users; permission system extensible |
| Suppliers | Records + purchases + purchase history; balances tracked via ledger |
| Expenses | Required; drawer-paid expenses are cash movements |
| Terminals | One or more POS terminals on the LAN, one server |

---

## 2. Architecture

### 2.1 Deployment

```
[Server PC]  XAMPP: Apache + PHP 8.2 + MariaDB   ← also usable as a POS terminal
      │  LAN (http://<server-ip>/retail-pos/)
      ├── [POS terminal 1] touch PC, Chrome kiosk, scanner, receipt printer, cash drawer
      └── [POS terminal 2] …
```

- **Apache, not `php -S`.** The built-in server handles one request at a time;
  multiple terminals need a real web server.
- **Terminal identity:** each terminal is registered once by the Admin
  (Registers page → "Register this device"). A long random device token is
  stored in a cookie on that browser and its hash in `registers`. Every sale,
  session and cash movement records the register.
- **Hardware in scope:** barcode scanner (USB keyboard mode; works in any
  browser) and receipt printer with a cash drawer attached. No scale.
- **Printing — decision DEFERRED to the printing phase.** Receipts, X and Z
  reports are built as HTML print templates (their own URLs), which works with
  both options:
  1. Chrome with `--kiosk-printing`: silent printing to the Windows default
     printer, drawer opens via the driver's "open drawer on print" setting.
     No extra software.
  2. A desktop terminal app (C# + WebView2): silent printing to a chosen
     printer, drawer opens on command, locked full-screen POS.
  A browser page cannot talk to a printer directly, only through the print
  dialog, which is why one of these two is needed. The server side is
  identical either way.
- **Offline:** the system needs the LAN, not the internet.

### 2.2 Code structure (same micro-MVC style as Daher Phone, plus a service layer)

```
Retail POS/
├── public/            web root: index.php + assets (all vendor files local)
├── app/
│   ├── Core/          router, DB, auth, CSRF, validator, permissions, audit, money
│   ├── Controllers/   thin: validate input, check permission, call a service, render
│   ├── Services/      business logic that spans tables (SaleService, CashService,
│   │                  StockService, ReturnService, PurchaseService, CountService)
│   ├── Models/        SQL only, one class per table/aggregate
│   └── Views/
├── config/            config.php + app.ini overlay
├── database/          schema.sql + migrations/
├── storage/           logs, backups (git-ignored)
└── docs/
```

**Why a service layer:** one sale touches `sales`, `sale_items`, `sale_payments`,
`stock_movements`, `cash_movements`, `customer_ledger`, `counters` and `audit_log`.
That logic belongs in one place (`SaleService::complete()`), inside one DB
transaction, not spread over controllers.

### 2.3 Built-in lessons from the Daher Phone audit

- PHP **and** the MariaDB session use the same timezone (`SET time_zone` on connect).
- Every route declares the permission it needs; the router enforces it server-side.
- Login lockout is tracked in the database by username + IP (`login_attempts`).
- Invoice discounts are spread across lines, so returns refund the true paid value.
- Money: `DECIMAL`, never float. Quantities: integers in base units (see §4).
- Distinct PDO placeholder names (native prepares).
- One money formatter shared by PHP and JS.
- Double-submit protection on every form. `.gitignore` for storage/backups/logs from the first commit.
- Financial records are append-only (see §14).

---

## 3. Core database

Conventions: InnoDB, utf8mb4_unicode_ci (Arabic-safe), `created_at` on every table.
USD amounts are `DECIMAL(12,2)`. LBP amounts are `DECIMAL(15,0)` (whole lira).
Exchange rate is `INT` (LBP per 1 USD). Per-base-unit cost is `DECIMAL(16,6)`
(a gram of charcoal costs fractions of a cent).

### 3.1 Identity and access

| Table | Key columns |
|---|---|
| `users` | id, username (unique), password_hash, full_name, role_id, pin_hash (null; for manager override), is_active, last_login_at |
| `roles` | id, name, is_system (Admin, Cashier cannot be deleted) |
| `permissions` | perm_key (PK, e.g. `sale.create`), label, group_name |
| `role_permissions` | role_id, perm_key |
| `login_attempts` | id, username, ip, success, created_at |
| `audit_log` | id, user_id, action, entity, entity_id, amount, currency, details (JSON), ip, register_id, created_at |

### 3.2 Setup

| Table | Key columns |
|---|---|
| `settings` | setting_key, setting_value (shop name/address/phone, receipt header/footer, denominations, rounding step 5000, max cashier discount %) |
| `registers` | id, name ("Register 01"), device_token_hash, is_active |
| `exchange_rates` | id, lbp_per_usd INT, set_by, created_at (history; current = latest row) |
| `counters` | name (PK: `invoice`, `return`, `purchase`, `session`, `z`, `count`), next_value — locked `FOR UPDATE` so numbers never repeat or skip |

### 3.3 Catalog

| Table | Key columns |
|---|---|
| `categories` | id, name, sort_order, color (touch tile), is_active |
| `products` | id, category_id, name, description, internal_code (unique, for items without barcode), **base_unit** ENUM('piece','g','ml'), **cost_per_base** DECIMAL(16,6), **stock_base** BIGINT, min_stock_base, allow_price_override, show_on_pos_grid, target_margin_pct (null), is_active |
| `product_units` | id, product_id, name ("Piece", "Pack", "Box", "Dozen", "kg"), **factor** INT (base units per 1 of this unit), retail_price, wholesale_price (null = not sold at that level), allows_fraction (kg yes, box no), is_default_sale, is_default_purchase, is_display (used for "9 boxes + 10 kg") |
| `barcodes` | id, product_unit_id, barcode (unique). A unit may have several barcodes; a product may have none |

### 3.4 Stock

| Table | Key columns |
|---|---|
| `stock_movements` | id, product_id, **base_qty** (signed BIGINT), type ENUM('opening','purchase','sale','sale_void','return_restock','waste','adjustment','stocktake'), reason (e.g. damaged, lost, returned-damaged), cost_per_base (frozen), ref_type, ref_id, user_id, session_id, created_at |
| `stock_counts` | id, count_no, status ENUM('open','confirmed','cancelled'), scope (all / category), started_by, started_at, confirmed_by, confirmed_at, note |
| `stock_count_lines` | id, count_id, product_id, expected_base (snapshot at start), counted_base (null until entered), diff_base, cost_per_base |

`products.stock_base` is the live quantity. It changes **only** through
`StockService`, which writes the movement in the same transaction.

### 3.5 Customers and suppliers (ledger-based balances)

| Table | Key columns |
|---|---|
| `customers` | id, name, phone, notes, default_price_level ENUM('retail','wholesale'), credit_limit_usd (null = no limit), is_active |
| `customer_ledger` | id, customer_id, type ENUM('sale_credit','payment','return_credit','adjustment'), amount_usd (**+ increases debt, − reduces it**), currency + amount_original (for payments), exchange_rate, sale_id, return_id, session_id, user_id, note |
| `suppliers` | id, name, phone, notes, is_active |
| `supplier_ledger` | id, supplier_id, type ENUM('purchase','payment','adjustment'), amount_usd (+ we owe more, − we owe less), purchase_id, expense_id, user_id, note |

Balance = `SUM(amount_usd)`. No running "paid_amount" column that can drift.

### 3.6 Purchasing and expenses

| Table | Key columns |
|---|---|
| `purchases` | id, purchase_no, supplier_id (null allowed), supplier_invoice_ref, purchase_date, total_usd, paid_now_usd, notes, user_id |
| `purchase_items` | id, purchase_id, product_id, product_unit_id, qty DECIMAL(12,3), base_qty, unit_cost_usd (per purchase unit), line_total_usd, cost_per_base (derived) |
| `expenses` | id, category, description, amount, currency ENUM('USD','LBP'), amount_usd, exchange_rate, paid_from ENUM('drawer','outside'), session_id (if drawer), supplier_id (if it is a supplier payment), user_id, expense_date |

### 3.7 Sales and payments

| Table | Key columns |
|---|---|
| `sales` | id, invoice_no (unique), register_id, session_id, user_id, customer_id (null = walk-in), price_level, subtotal_usd, discount_usd, total_usd, **rounding_usd**, cost_total_usd, **exchange_rate**, **change_usd**, **change_lbp**, status ENUM('completed','voided'), void_reason, voided_by, voided_at, notes, created_at |
| `sale_items` | id, sale_id, product_id, product_unit_id, product_name + unit_name (snapshots), qty DECIMAL(12,3), base_qty, unit_price_usd, line_discount_usd (incl. its share of the invoice discount), line_total_usd, cost_per_base (frozen), line_cost_usd, entry_mode ENUM('qty','amount'), price_overridden |
| `sale_payments` | id, sale_id, method ENUM('cash','card','credit'), currency ENUM('USD','LBP'), amount (in that currency), amount_usd (at the sale's rate) |

### 3.8 Returns

| Table | Key columns |
|---|---|
| `returns` | id, return_no, sale_id, session_id, register_id, user_id, customer_id, total_usd, exchange_rate, reason |
| `return_items` | id, return_id, sale_item_id, qty, base_qty, **condition** ENUM('restock','waste'), refund_usd, cost_usd |
| `return_refunds` | id, return_id, method ENUM('cash','debt_reduction'), currency, amount, amount_usd |

### 3.9 Cash sessions (X/Z)

| Table | Key columns |
|---|---|
| `cash_sessions` | id, session_no, register_id, user_id, status ENUM('open','counted','reviewed'), opened_at, counted_at, expected_usd, expected_lbp, counted_usd, counted_lbp, diff_usd, diff_lbp, z_no, reviewed_by, reviewed_at, review_note, force_closed |
| `cash_movements` | id, session_id, currency ENUM('USD','LBP'), **amount** (signed; physical cash in/out of the drawer), type ENUM('opening','sale','change','refund','debt_collection','expense','supplier_payment','cash_in','cash_out','void_reversal'), ref_type, ref_id, user_id, note, created_at |
| `cash_counts` | id, session_id, currency, denomination, count |

---

## 4. Quantities, units and weight products

**Rule: stock is always an integer number of base units** (piece, gram, or ml).
Packaging units are conversion factors. This one rule covers pieces, packs,
dozens, cartons, boxes and weight, with no floating-point error.

### Example — charcoal

```
Product: Charcoal (فحم)   base_unit = g   cost_per_base = $0.010000 ($10 per kg)
Units:   kg   factor 1,000    retail $15.00   allows_fraction = yes
         Box  factor 20,000   retail $280.00  allows_fraction = no   (is_display)

Stock:   200,000 g                       → shown "10 Box"
Sale:    2.5 kg  → base_qty 2,500 g
Stock:   197,500 g                       → shown "9 Box + 17.5 kg"
Sale:    1 Box   → base_qty 20,000 g     (priced $280, not 20 × $15)
```

**Display rule:** greedy from the largest display unit, remainder in the smallest
sellable unit ("9 Box + 17.5 kg", "3 Carton + 4 Pack + 2 Piece").

**Selling by amount** (the customer asks for 200,000 LBP of charcoal), rate 90,000:
1. Amount in USD = 200,000 / 90,000 = $2.2222 → line total **$2.22** (cents).
2. Grams = floor(2.22 / ($15.00 / 1000)) = floor(148.0) = **148 g** → qty shown 0.148 kg.
3. The line total stays exactly what the customer asked to pay. The quantity is
   rounded **down** to whole grams, so the shop never gives more than was paid for.

Fractional quantities are only allowed on units with `allows_fraction`
(kg yes; box, piece no). `base_qty = round(qty × factor)`.

Scale barcodes (weight printed in an EAN-13 starting with `2`) fit this model
later without schema changes.

---

## 5. Prices, price levels, cost and profit

- **Price level** (retail / wholesale) is chosen per sale. The customer's
  `default_price_level` pre-fills it. Choosing wholesale requires the
  `sale.wholesale` permission (or Admin PIN).
- Each sellable unit has its own retail and wholesale price. A NULL price means
  "not sold in this unit at this level".
- **Price override** at the till: `sale.price_override` permission or Admin PIN.
  Logged with the original price.
- **Discounts** (line or invoice): cashiers may give up to
  `settings.max_cashier_discount_pct` (default 0 %); beyond that needs the Admin PIN.

### Cost (kept simple: one cost price per product)

- Each product has exactly **one** current cost: `cost_per_base`.
- The Admin enters cost per unit (e.g. $200 per box); the system stores it per base unit.
- When a purchase is posted, the product's cost is updated by the **moving average**:

  ```
  new cost = (current stock × current cost + purchased qty × purchase cost)
             / (current stock + purchased qty)
  ```

  Example: 5 boxes @ $10 in stock, buy 5 @ $15 → new cost $12.50 per box.
  It is still one cost price per item, with no batches or layers. It stays
  accurate when purchase prices move. (Alternative: "last purchase cost wins";
  see open question Q1.)
- Every sale line **freezes** `cost_per_base` at the moment of sale. Changing
  cost later never rewrites past profit.
- Purchase costs stay in `purchase_items` forever; nothing is overwritten.
- **Margin display:** profit = price − cost; margin % = profit / cost
  ($5 cost, $8 price → $3, 60 %). `target_margin_pct` only *suggests* a new price
  when cost changes; the Admin confirms it. Shelf prices never change silently.

---

## 6. Sale totals

```
line_total    = qty × unit_price − line discount           (rounded to cents)
subtotal      = Σ line totals
invoice discount is spread across lines in proportion to their totals
total_usd     = subtotal − invoice discount
cost_total    = Σ base_qty × cost_per_base                 (frozen)
```

Spreading the discount means every line knows what the customer really paid for
it, so returns refund the correct amount (this fixes the Daher Phone discount bug).

---

## 7. Payments, change and LBP rounding

### 7.1 Recording payments

Each payment is one `sale_payments` row in its original currency, plus its USD
value at the sale's snapshot rate:

```
Invoice $23.00, rate 90,000
  cash  USD      20.00          → amount_usd 20.00
  cash  LBP  270,000            → amount_usd  3.00
  ------------------------------------------------
  paid_usd = 23.00  → fully paid
Cash movements:  +20 USD,  +270,000 LBP
```

Card is recorded in USD. Credit is recorded in USD and requires a customer; it
creates a `customer_ledger` entry. Any combination is allowed:
`$50 cash USD + 2,700,000 LBP + $20 card`, or `$40 cash + $60 credit`.

### 7.2 Paying fully or partly in LBP (rounding the amount due)

LBP notes cannot pay exact cents, so the LBP amount due is shown rounded to the
nearest 5,000:

```
Invoice $23.45 × 90,000 = 2,110,500 LBP → shown "Due: 2,110,000 LBP"
```

**Tolerance rule:** a remaining balance of ±2,500 LBP or less (half a rounding
step) counts as settled. The difference is stored in `sales.rounding_usd`
(here −$0.01, a shop loss). This keeps the invoice "fully paid" and the drawer exact.

### 7.3 Change

```
overpaid_usd = paid_usd − total_usd
```

The cashier chooses the change currency (USD, LBP, or split):

```
Invoice $23.20, customer pays $50 USD, change in LBP:
  overpaid         = $26.80
  exact change     = 26.80 × 90,000 = 2,412,000 LBP
  rounded (5,000)  = 2,410,000 LBP  → handed to the customer
  rounding         = 2,000 LBP less than exact = +$0.02 to the shop
Records:  sales.change_lbp = 2,410,000   sales.rounding_usd = +0.02
Cash movements:  +50 USD (sale),  −2,410,000 LBP (change)
```

Rounding examples (step 5,000): 16,000 → 15,000 · 17,000 → 15,000 ·
18,000 → 20,000 · 17,500 → 20,000 (tie rounds up; see Q3).

USD change is given in whole dollars where possible. Any USD cents left over go
to LBP change or to rounding (see the POS spec).

### 7.4 Where rounding goes

`rounding_usd` is part of revenue in reports ("Rounding +/−" line), so
**Σ payments − change = total + rounding** always holds, and the drawer records
only the physical cash that actually moved.

---

## 8. Cash sessions, X and Z reports

### 8.1 Rules

- A POS user must have an **open session on this register** to sell.
  One open session per register and one per user.
- **Open:** the cashier enters the opening USD and LBP. These become `opening` cash movements.
- Every physical cash event is a `cash_movements` row in its own currency:

| Event | USD | LBP |
|---|---|---|
| Cash received for a sale | + | + |
| Change given | − | − |
| Cash refund | − | − |
| Customer pays debt at the till | + | + |
| Expense or supplier paid from drawer | − | − |
| Cash in (float added) / cash out (withdrawal to safe) | ± | ± |
| Void of a sale in this session | reverses its movements | |

Card and credit never create cash movements.

```
Expected USD = Σ cash_movements.amount WHERE session = S AND currency = USD
Expected LBP = Σ cash_movements.amount WHERE session = S AND currency = LBP
```

### 8.2 X report (any time, does not close)

Built live from the session: sales total, retail vs wholesale, payments by
method + currency (cash USD, cash LBP, card, credit), change given, refunds,
debt collected, expenses/cash-out, invoice and return counts, expected USD and
expected LBP. It can be printed on the receipt printer.

### 8.3 Z report (closing)

1. The cashier chooses **Close session**.
2. **Blind count:** the cashier enters denomination counts
   (USD: 100/50/20/10/5/1; LBP: 100,000/50,000/20,000/10,000/5,000/1,000,
   configurable) **before** seeing the expected amount.
3. The system freezes expected, counted and difference per currency, assigns
   the next `z_no`, and sets status `counted`. The Z report prints
   (BALANCED / SHORTAGE / SURPLUS per currency).
4. The cashier can open a new session right away.
5. The Admin reviews the session (status `reviewed`) and may add a note.
   Differences are **never edited**. A correction is a new, logged adjustment
   entry with a reason.
6. The Admin can **force-close** a forgotten session (flagged `force_closed`,
   counted by the Admin).

A session's Z report always contains exactly the transactions linked to that
`session_id`, so totals can be drilled down to invoices.

---

## 9. Returns

- A return is always linked to the original invoice (search by number, customer,
  date, or product). Only up to the quantity sold minus the quantity already
  returned can be returned.
- Refund value per item = its **discount-adjusted** line value.
- **Condition per item:**
  - `restock` → `stock_movements` +base_qty (type `return_restock`), cost credited back.
  - `waste` → no sellable stock change. The item is recorded as a `waste`
    movement with reason "returned-damaged" at its frozen cost, and reports
    show it as a waste cost.
- **Refund method:**
  - If the invoice was on credit and the customer still owes money,
    `debt_reduction` goes first (`customer_ledger` return_credit).
  - Otherwise cash in USD or LBP at the **current** rate (kept simple, as
    decided). LBP is rounded to 5,000.
- The refund cash comes out of the drawer of the session **processing the
  return** (the current session), not the original sale's session.
- Returns need the `return.create` permission (cashier gets it or not; the
  Admin decides per role).

---

## 10. Customer credit

- Credit requires a customer. A credit portion adds `+amount` to `customer_ledger`.
- If the customer has a `credit_limit_usd`, exceeding it needs the Admin PIN.
- Collecting debt at the till is a **debt payment** screen. It writes a
  `customer_ledger` payment (−) and a cash movement in the currency received,
  so it is inside the X/Z totals.
- Balance, statement and aging come from `customer_ledger`.
  Customers with a non-zero balance cannot be deleted (only deactivated).

---

## 11. Purchases, suppliers, expenses

- **Posting a purchase:** each line adds `+base_qty` stock (type `purchase`),
  updates the product's moving-average cost (§5), and adds the supplier
  `purchase` entry to `supplier_ledger`.
- **Paying a supplier** is an expense with `supplier_id`. It creates a supplier
  ledger payment (−). If paid from the drawer, it also creates a cash movement
  in the open session.
- **Expenses** paid from the drawer create a cash movement; expenses paid
  "outside" (owner's pocket, bank) do not.
- Version 1 keeps suppliers simple: records, purchases, purchase history,
  balance. Purchase orders and returns to supplier come later.

---

## 12. Stocktaking (جرد)

1. The Admin starts a count (all products or one category). Expected quantities
   are snapshotted.
2. Selling continues during the count.
3. Counted quantities are entered by unit ("3 Box + 4.2 kg" → base units).
4. **On confirm**, the adjustment per line is `counted − expected_snapshot`,
   applied to the **current** stock. Sales made during the count are therefore
   not lost.
5. Each difference becomes a `stocktake` stock movement at the current cost.
   The count is stored with who started/confirmed it and when, and the value of
   gains and losses.

Example: snapshot says 100, the shelf is counted as 97 → adjustment −3.
If 5 were sold while the count was open, stock is already 95, so the final
stock is 95 − 3 = 92 (the 5 sales are not lost).

---

## 13. Voids

- A completed sale can be **voided** (never deleted) only in the **same open
  session**, only if it has no returns or debt payments, and only with the
  `sale.void` permission or Admin PIN.
- A void restocks items (type `sale_void`), reverses the cash movements, reverses
  the credit ledger entry, and keeps the invoice number (status `voided`, reason logged).
- After the session closes, corrections are returns, never voids.

---

## 14. Numbering, immutability, audit

- Numbers: `INV-000001`, `RTN-000001`, `PUR-000001`, `S-000001` (session),
  `Z-000001`, `CNT-000001`, from `counters` locked inside the transaction:
  unique and gap-free.
- **Append-only:** sales, sale_items, sale_payments, returns, cash_movements,
  ledgers, stock_movements and Z data are never updated or deleted by the
  application, except status changes (void, session status). Corrections are
  new rows.
- **Audit log** for: login/logout/failed login, session open/count/review/
  force-close, sale, void, return, price override, discount above limit, PIN
  override (who approved), cash in/out, expense, exchange-rate change, price and
  cost change, stock adjustment, stocktake confirm, user/permission change.

---

## 15. Permissions (initial set)

| Group | Keys |
|---|---|
| POS | `pos.use`, `sale.create`, `sale.discount`, `sale.price_override`, `sale.wholesale`, `sale.credit`, `sale.void`, `sale.reprint`, `return.create`, `debt.collect` |
| Cash | `session.open_own`, `session.close_own`, `session.view_all`, `session.review`, `session.force_close`, `cash.in_out` |
| Catalog | `product.view`, `product.manage`, `product.view_cost`, `price.manage`, `category.manage` |
| Stock | `stock.view`, `stock.adjust`, `purchase.manage`, `stocktake.manage` |
| Parties | `customer.manage`, `supplier.manage` |
| Money | `expense.manage`, `rate.manage` |
| Reports | `report.sales`, `report.profit`, `report.stock`, `report.cash` |
| Admin | `user.manage`, `role.manage`, `settings.manage`, `register.manage`, `backup.manage` |

- **Admin** role: all permissions.
- **Cashier** role default: `pos.use`, `sale.create`, `sale.credit`,
  `sale.reprint`, `return.create`, `debt.collect`, `session.open_own`,
  `session.close_own`. No cost, profit or admin access.
- **Admin PIN override:** when a cashier lacks a permission for one action, the
  Admin types their PIN on the POS screen. It is allowed once, and the audit log
  records who approved it.

---

## 16. Report definitions (numbers the reports will use)

```
Gross sales      = Σ total_usd of completed sales
Returns          = Σ refund value of returns (restock + waste)
Net sales        = Gross sales − Returns + Rounding
COGS             = Σ line_cost of sold lines − cost of ALL returned items
                   (restock and waste: the sale is reversed either way)
Waste cost       = cost of waste movements (damaged stock + damaged returns);
                   a damaged return therefore moves its cost from COGS to Waste
Gross profit     = Net sales − COGS − Waste cost
Net profit       = Gross profit − Expenses (excluding supplier payments, which
                   pay for stock already counted in COGS)
```

Payments are always reported per method **and** per original currency
(cash USD, cash LBP, card, credit), plus a USD-equivalent total at the
rates stored on each transaction.

---

## 17. Build order (phases)

1. Foundation: project, schema, auth, users/roles/permissions, registers, settings, layout, audit
2. Catalog: categories, products, units, barcodes, prices
3. Stock: movements, purchases, suppliers, moving-average cost, opening stock
4. Cash sessions + POS: touch screen, scanner, cart, price levels, payments/change/rounding, credit
5. Printing: receipt template, reprints, X/Z printouts
6. Returns (restock + waste), voids, debt collection
7. Expenses, cash in/out, supplier payments
8. Reports + stock table print
9. Stocktaking
10. Testing on real hardware, backups, deployment on the LAN

Each phase ends runnable and tested before the next starts.

---

## 18. Decisions Q1–Q6 (confirmed 2026-09-24)

| # | Question | Confirmed |
|---|---|---|
| Q1 | On a purchase, how does the product cost update? | **Moving average** (§5). Alternative: last purchase cost replaces it |
| Q2 | Selling more than the stock shows (stock not yet entered) | **Allowed with a warning**, listed for Admin review. Alternative: blocked |
| Q3 | LBP rounding tie (exactly 2,500 over a step) | **Round up** (customer-friendly) |
| Q4 | Card payments | Always recorded in **USD** |
| Q5 | Cashier discount without Admin PIN | **0 %** (every discount needs the PIN); Admin can raise it in settings |
| Q6 | Credit limit per customer | Optional field, empty = no limit |
