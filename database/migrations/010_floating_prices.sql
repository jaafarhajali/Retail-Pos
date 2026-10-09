-- 010_floating_prices.sql — 2026-10-09 (owner): some products (tobacco) follow a black-market price that changes two to
-- four times a day. Such a product is marked "price floats"; its prices are set on the "Today's prices" page and every
-- change is kept. A return of a floating product refunds the lower of what was paid and today's price. A customer who
-- took floating products on credit pays the price of the day he pays: the difference is charged to his ledger once,
-- per sale line, and remembered in debt_adjustments.
ALTER TABLE products ADD COLUMN price_floats TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_price_override;

CREATE TABLE price_changes (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_unit_id INT UNSIGNED  NOT NULL,
  retail_old      DECIMAL(12,2) NULL,
  retail_new      DECIMAL(12,2) NULL,
  wholesale_old   DECIMAL(12,2) NULL,
  wholesale_new   DECIMAL(12,2) NULL,
  user_id         INT UNSIGNED  NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pc_unit (product_unit_id),
  CONSTRAINT fk_pc_unit FOREIGN KEY (product_unit_id) REFERENCES product_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customer_ledger MODIFY type ENUM('sale_credit','payment','return_credit','adjustment','price_adjustment') NOT NULL;

CREATE TABLE debt_adjustments (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  customer_id  INT UNSIGNED  NOT NULL,
  sale_id      INT UNSIGNED  NOT NULL,
  sale_item_id INT UNSIGNED  NOT NULL,
  qty          DECIMAL(12,3) NOT NULL,
  price_old    DECIMAL(12,2) NOT NULL,
  price_new    DECIMAL(12,2) NOT NULL,
  amount_usd   DECIMAL(12,2) NOT NULL,
  ledger_id    BIGINT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED  NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_da_item (sale_item_id),
  KEY idx_da_customer (customer_id),
  CONSTRAINT fk_da_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE RESTRICT,
  CONSTRAINT fk_da_ledger FOREIGN KEY (ledger_id) REFERENCES customer_ledger (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
