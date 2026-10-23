<p align="center"><img src="public/assets/brand/mark.svg" width="96" height="96" alt="Retail POS"></p>

# Retail POS

A point-of-sale and stock system for a retail shop. Prices in US dollars, customers pay in dollars, Lebanese pounds or both, cash drawers counted at the end of every shift, stock that moves with every sale and purchase, and backups that run by themselves. It runs on one PC in the shop and is used from any PC, tablet or touch screen on the shop's network, in a browser.

This file is the user's guide. It says how to install the program, what to do on the first day, and how every page is used. Technical notes for developers are at the end.

---

## 1. Installing

### On a shop's PC (the normal way)

1. Copy `RetailPOS-Setup-<version>.exe` to the PC and run it. Windows asks to allow changes: Yes.
2. Keep the folder it proposes, `C:\RetailPOS`.
3. Type the shop name and the administrator's password (8 characters or more, write it down).
4. When it finishes it opens the browser on the sign-in page and puts **Retail POS** on the desktop.

The setup installs everything the program needs and starts it with the PC: the web server, the database, PHP and a **backup service**. Nothing else has to be installed, there is no XAMPP, and no internet is needed.

If another program already uses the web port, the setup takes the next free one and the desktop shortcut knows it. The address is then `http://localhost:8080/` instead of `http://localhost/`.

### Other PCs, tablets and touch screens in the shop

They need nothing installed. In their browser, open `http://<the server's IP>/` (for example `http://192.168.1.10/`). Find the server's IP in Windows → Settings → Network, and give the server a fixed IP in the router so it never changes. Add the page to the home screen or the desktop.

### Updating

Run the newer setup file on the same PC, keep the same folder. The data, the settings, the logo and the backups stay; the program is replaced and the database is brought up to date. Takes a minute.

### Uninstalling

Windows → Settings → Apps → Retail POS → Uninstall. It asks whether to delete the data and the backups too. Say No to keep them for a later installation.

---

## 2. The first day

Sign in as **admin** with the password typed during the setup, then go through **Administration** in the menu:

1. **Settings** — the shop name, address and phone (they print on receipts), the receipt header and footer, the **LBP rounding step** (5,000 LBP by default), the **maximum discount a cashier may give without your PIN**, the notes used to count the drawer, and the **logo**: upload the shop's logo and it appears on the menu, the sign-in page and every printout. Until then the program shows its own mark.
2. **Exchange rate** — today's rate of the dollar in pounds. Every payment is kept in the currency it was paid, with its dollar value at the rate of that moment. Change it whenever the rate moves.
3. **Users** — one user per person. Give each the **Cashier** role or make a role of your own. On the administrator's own user, set the **approval PIN**: 4 to 6 digits that a cashier types at the till when something needs your approval (a discount over the limit, a return, selling below cost, selling on credit over a limit). Without a PIN nothing can be approved.
4. **Roles & permissions** — what each role may do. Cashiers see no cost prices and cannot give discounts above the limit; change it if your shop works differently.
5. **Registers** — one per till (PC or touch screen). On each till PC, open the program once as administrator and press **Link this device** on the register it is: the till then knows its drawer.
6. **Backups** — plug a USB stick in the server, type its folder (for example `E:\RetailPOS-backups`) and Save. Set the time of the daily backup. See section 8.
7. **Categories** and **Products** — the catalogue, section 4. Or start with a few products and add the rest while selling.

Change the administrator password in the top-right menu if someone else typed it.

---

## 3. Selling

### Open a cash session

A cashier presses **Open a cash session** on the dashboard and types the money in the drawer at the start, in dollars and in pounds. From then on every sale, refund, debt payment, expense and cash in or out of that drawer is recorded in the session.

A session stays open until it is **counted and closed**: signing out, an expired sign-in or a closed browser do not end it. The cashier signs in again and is back in the same session. Only one session is open per register; the administrator sees every open session on **Cash sessions**.

### The till

**Till** in the menu opens the sale screen, made for a touch screen and a barcode scanner:

- **Scan** a barcode or **type** a name or code in the box at the top. Products with a button show in the grid, by category.
- A product sold several ways (kg or box, piece or pack) asks **which one**.
- **Qty / price** on a line: quantity, a weight sold by amount ("$5 of charcoal", only for kg and litre products), a line discount in % or $, a changed price when the product allows it.
- **Discount** on the whole invoice, % or $. The limit from Settings applies to every discount, line or invoice; above it the till asks for the administrator's PIN at once.
- **Hold** keeps a cart aside under a name and serves the next customer; the list of held carts brings it back.
- The **bin** on a line removes it; **Remove** removes the selected line.
- **Customer** chooses a regular customer: his price level (retail or wholesale), his debt, and the possibility to sell on **credit**.
- **Retail / Wholesale** switches the price level of the sale; wholesale needs the permission or the PIN.
- **Take payment**: one or several payments, cash in dollars, cash in pounds, card, credit. The till shows the change, in pounds rounded to 5,000 or in dollars, and refuses to give change the drawer does not hold. **Complete** prints the receipt.
- **Expense** records money paid out of the drawer (the electricity man, a delivery): what for, the amount in dollars or pounds, the PIN for a cashier.
- **Returns**: see below. **Session**: the session page. **Sales**: today's sales and reprints. **Light / Dark**: the screen's look, remembered per till.

The sale in progress survives a page reload and a lost sign-in. If the PC was switched off mid-sale, the cart is back when the till opens.

### Receipts and the printer

Receipts are printed from the browser. On the till PC, use Google Chrome started with `--kiosk-printing` so the receipt goes straight to the printer with no dialog, and set the receipt printer as the default printer with the paper width 80 mm (or 58 mm: add `&w=58` to the receipt address once and Chrome remembers). The cash drawer connected to the printer opens with the receipt. Any sale can be reprinted from **Sales**.

### Returns

**Returns** on the till (or Selling → Returns): the latest sales are listed, or find the sale by customer, item, phone or invoice number. Type how many of each item come back and whether they go **back to stock** or are **damaged**. The refund is what was really paid for them, discounts included. A customer who owes money gets the refund off his debt first, the rest in cash in dollars, pounds or both. A cashier needs the administrator's PIN for every return.

**Can't find the sale?** The pop-up has "Return without an invoice": scan or type the items, each refunded at today's price or less, never more.

### Customers and debts

**Customers**: name, phone, price level, credit limit. A sale on credit adds to the customer's debt; the till's done screen and the receipt say what he now owes. Money he pays later is collected **at the till** (Customer → Collect debt) so it goes into a drawer, in dollars, pounds or both, with change if he pays more. The customer page shows his whole history.

### Closing a session

**Session → Count and close**: the cashier counts the drawer, note by note, in both currencies, without seeing what the program expects (a blind count). The administrator sees the difference, can review it, and prints the **Z report**: sales, payments by kind, refunds, expenses, cash in and out, expected against counted. An **X report** can be printed any time during the shift. Reports are kept forever and reprinted from **Cash sessions**.

---

## 4. Stock

### Products

A product has a name (Arabic is fine everywhere), a category, a code (given automatically, can be changed), a photo, and **the ways it is sold**: Piece, Dozen, Pack, Box, Bag, Roll, Bottle, or by weight and volume (g, kg, ml, L). Each way has its own barcode, retail price and wholesale price. A "Box of 6" and a "Piece" of the same product share one stock, counted in pieces; a "Pack 250g" and "kg" share one stock in grams. Only kg and litre products can be sold in fractions or by amount.

The page also takes the **cost** (what you pay, per the unit you buy it in), the **minimum stock** that turns the product orange in lists, and an **opening stock**. Cost and profit are visible only to roles allowed to see them.

**Price floats**: tick it on a product whose price changes during the day (tobacco on the black market). Such products are priced on **Today's prices** (the whole list in one Save, every change kept with who and when), every till follows within a minute, a return refunds the lower of what was paid and today's price, and a customer who took them on credit pays the price of the day he pays.

A product never sold or bought can be deleted; otherwise switch it off: it keeps its history and leaves the till.

### Purchases and suppliers

**Purchases → New purchase**: the supplier, his invoice number, the lines (product, unit, quantity, cost). Stock goes up and the cost price becomes the moving average of what you paid. "Paid now" is what you paid the supplier, from outside the drawer (a supplier is never paid from a till); the rest is a debt to him. The supplier's invoice can be attached as a photo or PDF. **Suppliers** keeps each one's balance and ledger; a supplier payment is recorded on **Expenses** as a supplier payment.

### Stock, adjustments, stocktaking

**Stock** shows every product's quantity and value and every movement with its reason. **Adjust** corrects a quantity with a reason (damaged, lost, found, opening). **Stocktaking** counts the whole shop or one category: start, type what is counted, confirm; the differences are applied and kept. Count when the shop is closed.

---

## 5. Money

**Expenses**: rent, electricity, supplies, paid from a drawer (then they appear on that session's Z report) or from outside (the owner, the bank). A receipt can be attached. Supplier payments are entered here too and reduce the supplier's balance.

**Reports**: sales by day, product, category, cashier, price level; payments by kind and currency; profit (sales, returns, cost of goods, waste, expenses); stock value; customer debts; returns; waste; the printable stock table. Every report prints.

---

## 6. Administration

- **Users**, **Roles & permissions**, **Registers**, **Exchange rate**, **Settings**: section 2.
- **Audit log**: who did what and when, for every sale, return, price change, setting, approval and sign-in. Nothing is ever deleted from it.
- **Settings → Check this installation**: one page that says whether everything the PC needs is in place: PHP, the folders, the database, the backup tools, the services, disk space. When something is red, send a screenshot of it with your question.

---

## 7. Approvals and the PIN

A cashier cannot, alone: give a discount above the limit, change a price, sell below cost, sell at wholesale prices, sell on credit over a customer's limit, make a return, pay an expense from the drawer. Each of those asks for the **administrator's PIN** at the moment it happens, and the approval is written in the audit log with the administrator's name. Five wrong PINs in a row lock the PIN for a while.

---

## 8. Backups: never lose the data

The **backup service** makes one backup a day at the time set on **Backups** (02:00 by default). If the PC was off at that time, the backup is made as soon as it is on. A backup is one zip: the whole database and the uploaded images and logo. The newest 30 daily and 24 monthly backups are kept.

**The USB stick**: leave one in the server and type its folder on the Backups page. Every backup is copied there. If the PC dies, the stick has everything up to the last day. The dashboard turns amber when the stick is missing or the last backup is old.

**Google Drive**: connect a Google account on the Backups page and every daily backup is uploaded there too, out of the shop.

**Back up now** makes an extra backup at any moment, for example before a big change. The bin on a file deletes it from the PC only.

**Restore**: on the Backups page, choose a backup from the list or upload a zip from the stick, type your password and the word RESTORE. The shop goes back exactly to that moment. A safety backup of the current state is taken first, and every cash session must be closed. On a new PC: install, then restore the newest zip.

---

## 9. When something goes wrong

| What you see | What to do |
|---|---|
| The browser cannot reach the program | On the server, Windows → Services: `RetailPOSWeb` and `RetailPOSDB` must be running. Start them, or run the setup again. |
| "Something went wrong, error 500" | Settings → Check this installation, and send its screenshot and the file `C:\RetailPOS\app\storage\logs\php-error.log`. |
| The dashboard says the backup is old | The backup service stopped: Windows → Services → `RetailPOSBackup` → Start. Check the USB stick is plugged in. |
| A cashier cannot approve a discount | No PIN is set on the administrator's user, or it was typed wrong five times. |
| Receipts open as a page instead of printing | Chrome is not started with `--kiosk-printing`, or the receipt printer is not the default printer. |
| Prices on a till are old | The till follows Today's prices within a minute; press F5 on the till. |

---

## 10. For developers

The program is plain PHP 8.2 with MariaDB 10.4, no framework, no Composer, no internet: `public/` is the only web-reachable folder, `app/routes.php` lists every URL with the permission it needs, `app/Services/` holds the rules, `app/Models/` the SQL, `app/Views/` the pages, `database/migrations/` the numbered schema changes. `docs/ROADMAP.md` is the decisions log: every business rule with its date; `docs/specs/` the design; `docs/plans/` the phase plans.

Development install on XAMPP: `php bin/install.php --admin-password=...` creates the `retail_pos` database, applies the migrations and the first administrator; `config/app.ini` (never committed) overrides the database name, host, password and the mode (`env = production`). `php bin/seed-demo.php` fills an empty database with demo data.

Tests: `php tests/run.php` (about five minutes, on its own `retail_pos_test` database and port 8190); `tests/browser/` drives the till in headless Chrome. The Windows setup is built by `tools/installer/build.ps1` from git HEAD; see `tools/installer/README.md`.
