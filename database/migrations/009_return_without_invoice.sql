-- 009_return_without_invoice.sql — 2026-10-09 (owner): a return can be recorded without its invoice when the shop cannot
-- find it. returns.sale_id becomes optional, and every returned line carries its own product, unit and price, so a
-- return no longer depends on a sale_items row. Lines of earlier returns are filled from the sale line they point to.
ALTER TABLE returns DROP FOREIGN KEY fk_returns_sale;
ALTER TABLE returns MODIFY sale_id INT UNSIGNED NULL;
ALTER TABLE returns ADD CONSTRAINT fk_returns_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE RESTRICT;

ALTER TABLE return_items DROP FOREIGN KEY fk_ri_sale_item;
ALTER TABLE return_items MODIFY sale_item_id INT UNSIGNED NULL;
ALTER TABLE return_items ADD CONSTRAINT fk_ri_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE RESTRICT;
ALTER TABLE return_items
  ADD COLUMN product_id      INT UNSIGNED  NULL AFTER sale_item_id,
  ADD COLUMN product_unit_id INT UNSIGNED  NULL AFTER product_id,
  ADD COLUMN product_name    VARCHAR(200)  NULL AFTER product_unit_id,
  ADD COLUMN unit_name       VARCHAR(80)   NULL AFTER product_name,
  ADD COLUMN unit_price_usd  DECIMAL(12,2) NULL AFTER unit_name;
UPDATE return_items ri JOIN sale_items si ON si.id = ri.sale_item_id
   SET ri.product_id = si.product_id, ri.product_unit_id = si.product_unit_id, ri.product_name = si.product_name,
       ri.unit_name = si.unit_name, ri.unit_price_usd = si.unit_price_usd;
ALTER TABLE return_items ADD KEY idx_ri_product (product_id);
