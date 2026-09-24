-- 003_operations.sql — stock, suppliers, customers, sales, cash sessions, returns, expenses, stocktaking (spec §3.4–3.9)

CREATE TABLE suppliers (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  phone      VARCHAR(30)  NULL,
  notes      TEXT         NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(120) NOT NULL,
  phone               VARCHAR(30)  NULL,
  notes               TEXT         NULL,
  default_price_level ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  credit_limit_usd    DECIMAL(12,2) NULL,
  is_active           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_customers_name (name),
  KEY idx_customers_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_sessions (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  session_no   VARCHAR(20)   NOT NULL,
  register_id  INT UNSIGNED  NOT NULL,
  user_id      INT UNSIGNED  NOT NULL,
  status       ENUM('open','counted','reviewed') NOT NULL DEFAULT 'open',
  opened_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  counted_at   DATETIME      NULL,
  expected_usd DECIMAL(12,2) NULL,
  expected_lbp DECIMAL(15,0) NULL,
  counted_usd  DECIMAL(12,2) NULL,
  counted_lbp  DECIMAL(15,0) NULL,
  diff_usd     DECIMAL(12,2) NULL,
  diff_lbp     DECIMAL(15,0) NULL,
  z_no         VARCHAR(20)   NULL,
  closed_by    INT UNSIGNED  NULL,
  reviewed_by  INT UNSIGNED  NULL,
  reviewed_at  DATETIME      NULL,
  review_note  TEXT          NULL,
  force_closed TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_no (session_no),
  KEY idx_sessions_register (register_id, status),
  KEY idx_sessions_user (user_id, status),
  KEY idx_sessions_opened (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_movements (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED    NOT NULL,
  currency   ENUM('USD','LBP') NOT NULL,
  amount     DECIMAL(15,2)   NOT NULL,
  type       ENUM('opening','sale','change','refund','debt_collection','expense','supplier_payment','cash_in','cash_out','void_reversal') NOT NULL,
  ref_type   VARCHAR(30)     NULL,
  ref_id     BIGINT UNSIGNED NULL,
  user_id    INT UNSIGNED    NULL,
  note       VARCHAR(255)    NULL,
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_session (session_id, currency),
  CONSTRAINT fk_cm_session FOREIGN KEY (session_id) REFERENCES cash_sessions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cash_counts (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  session_id   INT UNSIGNED  NOT NULL,
  currency     ENUM('USD','LBP') NOT NULL,
  denomination INT UNSIGNED  NOT NULL,
  count        INT UNSIGNED  NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cc_session (session_id),
  CONSTRAINT fk_cc_session FOREIGN KEY (session_id) REFERENCES cash_sessions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchases (
  id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  purchase_no          VARCHAR(20)   NOT NULL,
  supplier_id          INT UNSIGNED  NULL,
  supplier_invoice_ref VARCHAR(60)   NULL,
  purchase_date        DATE          NOT NULL,
  total_usd            DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_now_usd         DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes                TEXT          NULL,
  user_id              INT UNSIGNED  NULL,
  session_id           INT UNSIGNED  NULL,
  created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchases_no (purchase_no),
  KEY idx_purchases_supplier (supplier_id),
  KEY idx_purchases_date (purchase_date),
  CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_items (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  purchase_id     INT UNSIGNED  NOT NULL,
  product_id      INT UNSIGNED  NOT NULL,
  product_unit_id INT UNSIGNED  NOT NULL,
  qty             DECIMAL(12,3) NOT NULL,
  base_qty        BIGINT        NOT NULL,
  unit_cost_usd   DECIMAL(12,2) NOT NULL,
  line_total_usd  DECIMAL(12,2) NOT NULL,
  cost_per_base   DECIMAL(16,6) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_pi_purchase (purchase_id),
  KEY idx_pi_product (product_id),
  CONSTRAINT fk_pi_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pi_unit FOREIGN KEY (product_unit_id) REFERENCES product_units (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_ledger (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_id INT UNSIGNED    NOT NULL,
  type        ENUM('purchase','payment','adjustment') NOT NULL,
  amount_usd  DECIMAL(12,2)   NOT NULL,
  purchase_id INT UNSIGNED    NULL,
  expense_id  INT UNSIGNED    NULL,
  user_id     INT UNSIGNED    NULL,
  note        VARCHAR(255)    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sl_supplier (supplier_id, created_at),
  CONSTRAINT fk_sl_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id    INT UNSIGNED    NOT NULL,
  base_qty      BIGINT          NOT NULL,
  type          ENUM('opening','purchase','sale','sale_void','return_restock','waste','adjustment','stocktake') NOT NULL,
  reason        VARCHAR(100)    NULL,
  cost_per_base DECIMAL(16,6)   NOT NULL DEFAULT 0,
  ref_type      VARCHAR(30)     NULL,
  ref_id        BIGINT UNSIGNED NULL,
  user_id       INT UNSIGNED    NULL,
  session_id    INT UNSIGNED    NULL,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_product (product_id, created_at),
  KEY idx_sm_type (type, created_at),
  CONSTRAINT fk_sm_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  invoice_no     VARCHAR(20)   NOT NULL,
  register_id    INT UNSIGNED  NOT NULL,
  session_id     INT UNSIGNED  NOT NULL,
  user_id        INT UNSIGNED  NOT NULL,
  customer_id    INT UNSIGNED  NULL,
  price_level    ENUM('retail','wholesale') NOT NULL DEFAULT 'retail',
  subtotal_usd   DECIMAL(12,2) NOT NULL,
  discount_usd   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_usd      DECIMAL(12,2) NOT NULL,
  rounding_usd   DECIMAL(12,2) NOT NULL DEFAULT 0,
  cost_total_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  exchange_rate  INT UNSIGNED  NOT NULL,
  change_usd     DECIMAL(12,2) NOT NULL DEFAULT 0,
  change_lbp     DECIMAL(15,0) NOT NULL DEFAULT 0,
  status         ENUM('completed','voided') NOT NULL DEFAULT 'completed',
  void_reason    VARCHAR(255)  NULL,
  voided_by      INT UNSIGNED  NULL,
  voided_at      DATETIME      NULL,
  notes          VARCHAR(255)  NULL,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_invoice (invoice_no),
  KEY idx_sales_session (session_id),
  KEY idx_sales_created (created_at),
  KEY idx_sales_customer (customer_id),
  CONSTRAINT fk_sales_session FOREIGN KEY (session_id) REFERENCES cash_sessions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_items (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_id           INT UNSIGNED  NOT NULL,
  product_id        INT UNSIGNED  NOT NULL,
  product_unit_id   INT UNSIGNED  NOT NULL,
  product_name      VARCHAR(150)  NOT NULL,
  unit_name         VARCHAR(30)   NOT NULL,
  qty               DECIMAL(12,3) NOT NULL,
  base_qty          BIGINT        NOT NULL,
  unit_price_usd    DECIMAL(12,2) NOT NULL,
  line_discount_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total_usd    DECIMAL(12,2) NOT NULL,
  cost_per_base     DECIMAL(16,6) NOT NULL DEFAULT 0,
  line_cost_usd     DECIMAL(12,2) NOT NULL DEFAULT 0,
  entry_mode        ENUM('qty','amount') NOT NULL DEFAULT 'qty',
  price_overridden  TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_si_sale (sale_id),
  KEY idx_si_product (product_id),
  CONSTRAINT fk_si_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE RESTRICT,
  CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
  CONSTRAINT fk_si_unit FOREIGN KEY (product_unit_id) REFERENCES product_units (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_payments (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  sale_id    INT UNSIGNED  NOT NULL,
  method     ENUM('cash','card','credit') NOT NULL,
  currency   ENUM('USD','LBP') NOT NULL,
  amount     DECIMAL(15,2) NOT NULL,
  amount_usd DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sp_sale (sale_id),
  CONSTRAINT fk_sp_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE held_sales (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  register_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  cart_json   LONGTEXT     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_held_register (register_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_ledger (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id     INT UNSIGNED    NOT NULL,
  type            ENUM('sale_credit','payment','return_credit','adjustment') NOT NULL,
  amount_usd      DECIMAL(12,2)   NOT NULL,
  currency        ENUM('USD','LBP') NULL,
  amount_original DECIMAL(15,2)   NULL,
  exchange_rate   INT UNSIGNED    NULL,
  sale_id         INT UNSIGNED    NULL,
  return_id       INT UNSIGNED    NULL,
  session_id      INT UNSIGNED    NULL,
  user_id         INT UNSIGNED    NULL,
  note            VARCHAR(255)    NULL,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cl_customer (customer_id, created_at),
  CONSTRAINT fk_cl_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE returns (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  return_no     VARCHAR(20)   NOT NULL,
  sale_id       INT UNSIGNED  NOT NULL,
  session_id    INT UNSIGNED  NOT NULL,
  register_id   INT UNSIGNED  NOT NULL,
  user_id       INT UNSIGNED  NOT NULL,
  customer_id   INT UNSIGNED  NULL,
  total_usd     DECIMAL(12,2) NOT NULL,
  exchange_rate INT UNSIGNED  NOT NULL,
  reason        VARCHAR(255)  NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_returns_no (return_no),
  KEY idx_returns_sale (sale_id),
  KEY idx_returns_session (session_id),
  CONSTRAINT fk_returns_sale FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE return_items (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  return_id      INT UNSIGNED  NOT NULL,
  sale_item_id   INT UNSIGNED  NOT NULL,
  qty            DECIMAL(12,3) NOT NULL,
  base_qty       BIGINT        NOT NULL,
  item_condition ENUM('restock','waste') NOT NULL,
  refund_usd     DECIMAL(12,2) NOT NULL,
  cost_usd       DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ri_return (return_id),
  KEY idx_ri_sale_item (sale_item_id),
  CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES returns (id) ON DELETE RESTRICT,
  CONSTRAINT fk_ri_sale_item FOREIGN KEY (sale_item_id) REFERENCES sale_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE return_refunds (
  id         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  return_id  INT UNSIGNED  NOT NULL,
  method     ENUM('cash','debt_reduction') NOT NULL,
  currency   ENUM('USD','LBP') NOT NULL,
  amount     DECIMAL(15,2) NOT NULL,
  amount_usd DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_rr_return (return_id),
  CONSTRAINT fk_rr_return FOREIGN KEY (return_id) REFERENCES returns (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  category      VARCHAR(60)   NOT NULL,
  description   VARCHAR(255)  NULL,
  amount        DECIMAL(15,2) NOT NULL,
  currency      ENUM('USD','LBP') NOT NULL,
  amount_usd    DECIMAL(12,2) NOT NULL,
  exchange_rate INT UNSIGNED  NOT NULL,
  paid_from     ENUM('drawer','outside') NOT NULL,
  session_id    INT UNSIGNED  NULL,
  supplier_id   INT UNSIGNED  NULL,
  user_id       INT UNSIGNED  NULL,
  expense_date  DATE          NOT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expenses_date (expense_date),
  KEY idx_expenses_session (session_id),
  KEY idx_expenses_supplier (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_counts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  count_no     VARCHAR(20)  NOT NULL,
  status       ENUM('open','confirmed','cancelled') NOT NULL DEFAULT 'open',
  scope        VARCHAR(20)  NOT NULL DEFAULT 'all',
  category_id  INT UNSIGNED NULL,
  started_by   INT UNSIGNED NOT NULL,
  started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME     NULL,
  note         TEXT         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_counts_no (count_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_count_lines (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  count_id      INT UNSIGNED  NOT NULL,
  product_id    INT UNSIGNED  NOT NULL,
  expected_base BIGINT        NOT NULL,
  counted_base  BIGINT        NULL,
  diff_base     BIGINT        NULL,
  cost_per_base DECIMAL(16,6) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scl (count_id, product_id),
  CONSTRAINT fk_scl_count FOREIGN KEY (count_id) REFERENCES stock_counts (id) ON DELETE RESTRICT,
  CONSTRAINT fk_scl_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
