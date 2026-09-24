# Phase 2 — Catalog screens (short screen spec)

**Date:** 2026-09-24 · **Status:** for owner review with the Phase 2 plan · **Spec sections:** core design §3.3, §4, §5

Three admin screens. All use the Phase 1 layout (sidebar + top bar), Bootstrap 5, touch-sized
controls, English UI, `dir="auto"` on every name field. The POS grid itself is Phase 4; here
the catalog is only *defined*.

## Who sees what

| Permission | Products list | Product form | Cost & margin | Prices | Categories |
|---|---|---|---|---|---|
| `product.view` | read | — | — | — | — |
| `product.manage` | read | create / edit, units, barcodes, image | — | — | — |
| `product.view_cost` | cost & value columns | cost card | yes | — | — |
| `price.manage` | — | — | — | retail / wholesale per unit | — |
| `category.manage` | — | — | — | — | all |

The default Cashier role has none of these: cashiers never see cost, margin or these pages.
Cost is never rendered, not just hidden, when `product.view_cost` is missing, and a posted
cost is refused server-side.

## 1. Products list — `index.php?r=products`

```
┌ Products ───────────────────────────────────────────────────────── [ + Add product ] ┐
│ [Search name / code / barcode….] [Category ▾] [Stock: All ▾] [Unit….] [$ min] [$ max]   │
│ [☐ Show inactive]                                          [ Filter ]  [ 🖨 Print table ] │
├──────────┬────────────────────┬───────────┬──────────────────┬─────────┬────────────────┤
│ Code     │ Product            │ Category  │ Stock            │ Retail  │                │
├──────────┼────────────────────┼───────────┼──────────────────┼─────────┼────────────────┤
│ P-000001 │ فحم  (Charcoal)    │ ● Charcoal│ 9 Box + 17.5 kg  │ $15.00/kg  $280.00/Box │ [Edit] │
│ P-000002 │ Al Fakher Apple 50g│ ● Tobacco │ 3 Carton + 4 Pack│ $3.50/Piece            │ [Edit] │
│ P-000003 │ Hose (silicone)    │ ● Access. │ 0 Piece  ⚠ low   │ —  (no price)          │ [Edit] │
└──────────┴────────────────────┴───────────┴──────────────────┴─────────┴────────────────┘
  Page 1 of 3 · 62 rows                                        [Previous] [Next]
```

- With `product.view_cost` two more columns appear: **Cost** (per default sale unit) and
  **Stock value** (stock × cost per base).
- Stock is shown with the §4 display rule (greedy from the largest *display* unit, remainder in
  the smallest unit). "⚠ low" when stock ≤ minimum stock; "out" badge when ≤ 0.
- Retail column lists every unit that has a retail price ("$15.00/kg · $280.00/Box").
- Search matches the name (any language), the internal code, or an **exact barcode** (a
  scanner typed into the search box finds the product).

## 2. Product form — `index.php?r=products/edit&id=…` (create shows only the first card)

```
┌ Product: فحم ──────────────────────────────────────────────────────────────────────────┐
│ ┌ Basics ───────────────────────────┐  ┌ Image ───────────────────────────────────────┐ │
│ │ Name        [فحم                ] │  │ [   photo 200×200   ]  [Choose file] [Upload]  │ │
│ │ Code        [P-000001           ] │  │                        [Remove image]          │ │
│ │ Category    [Charcoal        ▾ ] │  └────────────────────────────────────────────────┘ │
│ │ Base unit   (•) piece ( ) g ( ) ml│  ┌ Cost & margin  (product.view_cost only) ──────┐ │
│ │   (locked once units exist)       │  │ Cost per g   $0.010000   ($10.00 per kg)       │ │
│ │ Description [                   ] │  │ Set cost: [ 200.00 ] per [Box ▾]  [Save cost]  │ │
│ │ Min. stock  [ 2 ] [Box ▾]         │  │ Margins: kg $15.00 → profit $5.00 (50 %)       │ │
│ │ ☑ Show on POS grid                │  │          Box $280.00 → profit $80.00 (40 %)    │ │
│ │ ☐ Allow price override at the till│  │ Target margin [ 40 ] % → suggested Box $280.00 │ │
│ │ ☑ Active          [Save product]  │  └────────────────────────────────────────────────┘ │
│ └───────────────────────────────────┘                                                     │
│ ┌ Units and prices ────────────────────────────────────────────────────────────────────┐ │
│ │ Unit  Factor (g)  Fraction  Retail    Wholesale  Default sale  Default buy  Display    │ │
│ │ kg     1,000       yes      [15.00]   [13.00]       (•)            ( )        ☐  [Save][Delete] │
│ │ Box   20,000       no       [280.00]  [250.00]      ( )            (•)        ☑  [Save][Delete] │
│ │ ── Add unit: [Name ▾ Piece/Pack/Box/Carton/Dozen/kg/g/L/ml] [factor] ☐ fraction [Add] ──│ │
│ └──────────────────────────────────────────────────────────────────────────────────────┘ │
│ ┌ Barcodes ──────────────────────────────────────────────────────────────────────────┐   │
│ │ 6291041500213 → kg   [Remove]      Add: [scan or type…] for unit [kg ▾] [Add]      │   │
│ └────────────────────────────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────────────────────────┘
```

- **Code** is pre-filled `P-000001`… (from `counters.product`) and may be overwritten; unique.
- **Base unit** is chosen once; it is locked while the product has units (factors depend on it).
- **Units:** factor = base units per 1 of this unit (kg = 1,000 g; Dozen = 12 piece). The first
  unit becomes the default sale and purchase unit. Exactly one default sale unit and one default
  purchase unit per product. `Display` marks units used by the "9 Box + 17.5 kg" rule.
  Prices are per unit; an empty price means "not sold in this unit at that level" (§5).
  The prices columns are editable only with `price.manage`; otherwise shown read-only.
- **Cost** is entered per *any* unit ("$200 per Box") and stored per base unit
  (`$0.010000` per g). Margin per unit = price − cost×factor; % = profit / cost (§5 example:
  cost $5, price $8 → $3, 60 %). `Target margin` only *suggests*; nothing changes silently.
- **Barcodes:** several per unit, unique across the whole catalog; the add box autofocuses so
  a scanner can fill it. A product may have none.
- **Image:** optional jpg/png/webp ≤ 5 MB, resized to fit 400×400 and saved as jpg under
  `public/uploads/products/`. Shown on this form and (Phase 4) on the POS tile.

## 3. Categories — `index.php?r=categories`

```
┌ Categories ─────────────────────────────────────────┐  ┌ New category ──────────────┐
│ Order  Colour  Name          Products  Active        │  │ Name   [              ]     │
│  10     ●      Charcoal         4       yes   [Edit] │  │ Colour [■ #e67e22]          │
│  20     ●      Tobacco         31       yes   [Edit] │  │ Order  [ 30 ]               │
│  30     ●      Accessories     12       yes   [Edit] │  │        [Create category]    │
└─────────────────────────────────────────────────────┘  └────────────────────────────┘
```

- Colour is the POS tile colour (§3.3), an HTML colour input (`#rrggbb`).
- Order sorts the POS grid tabs; lower first.
- A category with products cannot be deleted (deactivate instead); an empty one can.

## 4. Printable product / stock table — `index.php?r=products/print`

Plain print layout (no sidebar), one table grouped by category: code, name, stock (display
rule), and with `product.view_cost` the cost per base unit and stock value, plus a total value
line. Retail price per unit. Opens in a new tab; a **Print** button calls `window.print()`
(the Phase 5 printing decision does not affect this A4 table).
