-- Two stocks and more (Aya, 2026-11-09, the owner's "mestawde3 and magazine"): stock lives in
-- locations - a warehouse where goods arrive and a shop the tills sell from, and more of either
-- can be added later (a second branch, a second warehouse). products.stock_base stays the TOTAL
-- across locations, so every report keeps reading true; product_stocks carries the per-place
-- numbers, and every stock movement says where it happened. All stock existing today starts in
-- the Shop, because that is what the system has been counting so far.
CREATE TABLE locations (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(50)  NOT NULL,
  type       ENUM('shop','warehouse') NOT NULL DEFAULT 'warehouse',
  is_default_sale    TINYINT(1) NOT NULL DEFAULT 0,
  is_default_receive TINYINT(1) NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO locations (name, type, is_default_sale, is_default_receive) VALUES
('Shop', 'shop', 1, 0),
('Warehouse', 'warehouse', 0, 1);

CREATE TABLE product_stocks (
  product_id  INT UNSIGNED NOT NULL,
  location_id INT UNSIGNED NOT NULL,
  stock_base  BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, location_id),
  CONSTRAINT fk_ps_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO product_stocks (product_id, location_id, stock_base)
SELECT p.id, (SELECT id FROM locations WHERE is_default_sale = 1), p.stock_base FROM products p;

ALTER TABLE stock_movements ADD COLUMN location_id INT UNSIGNED NULL AFTER product_id;
ALTER TABLE stock_movements MODIFY type ENUM('opening','purchase','sale','sale_void','return_restock','waste','adjustment','stocktake','transfer') NOT NULL;
UPDATE stock_movements SET location_id = (SELECT id FROM locations WHERE is_default_sale = 1);
ALTER TABLE stock_movements ADD CONSTRAINT fk_sm_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT;

CREATE TABLE transfers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_no      VARCHAR(20)  NOT NULL,
  from_location_id INT UNSIGNED NOT NULL,
  to_location_id   INT UNSIGNED NOT NULL,
  note             VARCHAR(255) NULL,
  user_id          INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfers_no (transfer_no),
  CONSTRAINT fk_tr_from FOREIGN KEY (from_location_id) REFERENCES locations (id) ON DELETE RESTRICT,
  CONSTRAINT fk_tr_to FOREIGN KEY (to_location_id) REFERENCES locations (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO counters (name, next_value) VALUES ('transfer', 1);

ALTER TABLE registers ADD COLUMN location_id INT UNSIGNED NULL AFTER name;
ALTER TABLE registers ADD CONSTRAINT fk_reg_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT;
ALTER TABLE purchases ADD COLUMN location_id INT UNSIGNED NULL AFTER supplier_id;
ALTER TABLE purchases ADD CONSTRAINT fk_pu_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT;
ALTER TABLE stock_counts ADD COLUMN location_id INT UNSIGNED NULL AFTER scope;
UPDATE stock_counts SET location_id = (SELECT id FROM locations WHERE is_default_sale = 1);
ALTER TABLE stock_counts ADD CONSTRAINT fk_sc_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE RESTRICT;

INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('stock.transfer', 'Move stock between locations', 'Stock', 26);
