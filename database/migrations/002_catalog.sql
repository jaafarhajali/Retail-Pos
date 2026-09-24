-- 002_catalog.sql — categories, products, units, barcodes (spec §3.3, §4, §5)

CREATE TABLE categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(80)  NOT NULL,
  color      CHAR(7)      NOT NULL DEFAULT '#6c757d',
  sort_order INT          NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stock_base changes only through StockService (Phase 3); cost_per_base is per base unit (g of charcoal).
CREATE TABLE products (
  id                   INT UNSIGNED           NOT NULL AUTO_INCREMENT,
  category_id          INT UNSIGNED           NULL,
  name                 VARCHAR(150)           NOT NULL,
  description          TEXT                   NULL,
  internal_code        VARCHAR(30)            NOT NULL,
  base_unit            ENUM('piece','g','ml') NOT NULL DEFAULT 'piece',
  cost_per_base        DECIMAL(16,6)          NOT NULL DEFAULT 0,
  stock_base           BIGINT                 NOT NULL DEFAULT 0,
  min_stock_base       BIGINT                 NULL,
  allow_price_override TINYINT(1)             NOT NULL DEFAULT 0,
  show_on_pos_grid     TINYINT(1)             NOT NULL DEFAULT 1,
  target_margin_pct    DECIMAL(6,2)           NULL,
  image_file           VARCHAR(60)            NULL,
  is_active            TINYINT(1)             NOT NULL DEFAULT 1,
  created_at           DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_code (internal_code),
  KEY idx_products_name (name),
  KEY idx_products_category (category_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- factor = base units per 1 of this unit (kg = 1000 g, Dozen = 12 piece). NULL price = not sold at that level.
CREATE TABLE product_units (
  id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  product_id          INT UNSIGNED  NOT NULL,
  name                VARCHAR(30)   NOT NULL,
  factor              INT UNSIGNED  NOT NULL,
  retail_price        DECIMAL(12,2) NULL,
  wholesale_price     DECIMAL(12,2) NULL,
  allows_fraction     TINYINT(1)    NOT NULL DEFAULT 0,
  is_default_sale     TINYINT(1)    NOT NULL DEFAULT 0,
  is_default_purchase TINYINT(1)    NOT NULL DEFAULT 0,
  is_display          TINYINT(1)    NOT NULL DEFAULT 0,
  created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_units_product_name (product_id, name),
  CONSTRAINT fk_units_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE barcodes (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_unit_id INT UNSIGNED NOT NULL,
  barcode         VARCHAR(64)  NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_barcodes_barcode (barcode),
  KEY idx_barcodes_unit (product_unit_id),
  CONSTRAINT fk_barcodes_unit FOREIGN KEY (product_unit_id) REFERENCES product_units (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO counters (name, next_value) VALUES ('product', 1);
