# Retail POS — instructions for Claude

Retail POS is a point-of-sale and stock system for an Argile / Hookah shop in Lebanon.
Prices are in USD; customers pay in USD and/or LBP. It runs on XAMPP on a local
network: one server, one or more touch-screen POS terminals with a USB barcode
scanner and a receipt printer with a cash drawer.

## Read these first, every session

1. `docs/ROADMAP.md`: phase status, the scope of every phase, and the **decisions log**
   (every business decision the owner made, with dates). Do not re-ask what is already decided there.
2. `docs/specs/2026-09-24-core-design.md`: the **approved design**, the source of truth for
   the database, money and cash rules, returns, stocktaking and permissions.
3. `docs/plans/`: one implementation plan per phase. Execute the plan of the current phase.

## How we work (agreed with the owner)

- **One phase at a time:** plan → owner reviews the plan → implement task by task →
  owner checks it in the browser → mark it done in `docs/ROADMAP.md` → next phase.
- **Never write product code for a phase without an approved plan.** Write the next
  plan from the spec only after the previous phase is finished, because plans depend on the
  exact names that earlier phases created.
- **Claude writes every phase plan** (Phases 2–10), in a session in this folder, when
  the owner says the previous phase works. Steps: read the spec sections listed for
  that phase in `docs/ROADMAP.md` and the code that already exists, ask the owner that
  phase's "Decide before the plan" questions, write
  `docs/plans/YYYY-MM-DD-phaseN-<name>.md` in the same format as the Phase 1 plan, and
  wait for the owner's approval before coding. The owner only answers questions and approves.
- Inside a plan: tests first, each task ends with all tests passing and one commit.
  Don't skip a failing test; fix the cause.
- A business-rule question that the spec and the decisions log don't answer →
  **ask the owner**, don't guess. Record the answer in the decisions log.
- Screens get a short screen spec (with a mockup) before their plan, especially the POS screen (Phase 4).

## Environment

- Project path has a **space**: `C:\xampp\htdocs\Retail POS`. Always quote it.
  Git Bash: `cd "/c/xampp/htdocs/Retail POS"`.
- PHP 8.2.12: `/c/xampp/php/php.exe`. MariaDB 10.4 on `127.0.0.1:3306`, user `root`,
  empty password (XAMPP → MySQL must be started). Dev database `retail_pos`; the test
  suite drops and rebuilds `retail_pos_test` for every test.
- Tests: `/c/xampp/php/php.exe tests/run.php [Filter]`. HTTP tests start their own
  server on port 8190.
- No Composer, npm or CDN. Vendor assets are local in `public/assets/vendor/`.

## Rules that must never be broken

- UI text in **English only**. User data may be **Arabic**: utf8mb4 everywhere, `dir="auto"` on name fields.
- Money is `DECIMAL` (USD `DECIMAL(12,2)`, LBP whole numbers), never float in the database.
  Stock is an **integer of base units** (piece / gram / ml).
- PHP and the MariaDB session use the same timezone (`SET time_zone` on connect).
- PDO native prepares: a named placeholder appears **once** per SQL statement (`:q1`, `:q2`).
- Every route in `app/routes.php` declares its access (`Router::GUEST`, `Router::AUTH`
  or a permission key). The Router enforces CSRF on every POST. Never check
  permissions only in the frontend.
- Financial records (sales, payments, returns, cash movements, ledgers, stock movements,
  Z reports, audit log) are **append-only**. Corrections are new rows.
- Stock changes only through `StockService` (from Phase 3). Payments are stored in their
  original currency plus the USD value at the transaction's snapshot rate.
- Invoice discounts are spread across lines, so returns refund what was really paid.
- Never commit backups, logs or `config/app.ini` (`.gitignore`).
- Before a test-install of anything that touches a real installation, check it is not the owner's live system.

## Owner

- Works in VS Code on Windows, writes informal English, and prefers clear answers with
  concrete numeric examples and a recommendation instead of a list of options.
- Tests features manually in the browser after each phase.
- Same author as **Daher Phone** (`C:\xampp\htdocs\daher store`), a repair-shop system
  in the same code style. Its audit lessons are built into this project's rules above.

## Current status

See the phase table in `docs/ROADMAP.md`. As of 2026-09-24: the Phase 1 plan is written and
approved for review, nothing is implemented yet, and the execution method is not chosen
(Native recommended).
