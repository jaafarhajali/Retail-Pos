-- 001_foundation.sql — identity, access and setup tables (spec §3.1-3.2, §15)

CREATE TABLE roles (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(50)  NOT NULL,
  is_system  TINYINT(1)   NOT NULL DEFAULT 0,
  is_super   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  perm_key   VARCHAR(50)  NOT NULL,
  label      VARCHAR(100) NOT NULL,
  group_name VARCHAR(30)  NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id  INT UNSIGNED NOT NULL,
  perm_key VARCHAR(50)  NOT NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (perm_key) REFERENCES permissions (perm_key) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username             VARCHAR(50)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  full_name            VARCHAR(100) NOT NULL,
  role_id              INT UNSIGNED NOT NULL,
  pin_hash             VARCHAR(255) NULL,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 0,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at        DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_role (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username   VARCHAR(50) NOT NULL,
  ip         VARCHAR(45) NOT NULL,
  success    TINYINT(1)  NOT NULL,
  created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_user_ip (username, ip, id),
  KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(50)  NOT NULL,
  device_token_hash CHAR(64)     NULL,
  is_active         TINYINT(1)   NOT NULL DEFAULT 1,
  bound_at          DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_registers_name (name),
  UNIQUE KEY uq_registers_token (device_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No foreign keys on audit_log: its rows must outlive users and registers.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED    NULL,
  action      VARCHAR(60)     NOT NULL,
  entity      VARCHAR(40)     NULL,
  entity_id   BIGINT UNSIGNED NULL,
  amount      DECIMAL(15,2)   NULL,
  currency    CHAR(3)         NULL,
  details     LONGTEXT        NULL,
  ip          VARCHAR(45)     NULL,
  register_id INT UNSIGNED    NULL,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action),
  KEY idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key   VARCHAR(50) NOT NULL,
  setting_value TEXT        NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exchange_rates (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lbp_per_usd INT UNSIGNED NOT NULL,
  set_by      INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rates_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE counters (
  name       VARCHAR(20)     NOT NULL,
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (id, name, is_system, is_super) VALUES
(1, 'Admin', 1, 1),
(2, 'Cashier', 1, 0);

INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('pos.use', 'Use the POS screen', 'POS', 1),
('sale.create', 'Complete sales', 'POS', 2),
('sale.discount', 'Give discounts', 'POS', 3),
('sale.price_override', 'Change prices at the till', 'POS', 4),
('sale.wholesale', 'Sell at wholesale prices', 'POS', 5),
('sale.credit', 'Sell on credit', 'POS', 6),
('sale.void', 'Void sales in the open session', 'POS', 7),
('sale.reprint', 'Reprint receipts', 'POS', 8),
('return.create', 'Process returns', 'POS', 9),
('debt.collect', 'Collect customer debt at the till', 'POS', 10),
('session.open_own', 'Open own cash session', 'Cash', 11),
('session.close_own', 'Close own cash session', 'Cash', 12),
('session.view_all', 'View all cash sessions', 'Cash', 13),
('session.review', 'Review closed sessions', 'Cash', 14),
('session.force_close', 'Force-close sessions', 'Cash', 15),
('cash.in_out', 'Record cash in and cash out', 'Cash', 16),
('product.view', 'View products', 'Catalog', 17),
('product.manage', 'Create and edit products', 'Catalog', 18),
('product.view_cost', 'See cost prices and profit', 'Catalog', 19),
('price.manage', 'Change selling prices', 'Catalog', 20),
('category.manage', 'Manage categories', 'Catalog', 21),
('stock.view', 'View stock levels and movements', 'Stock', 22),
('stock.adjust', 'Adjust stock and record waste', 'Stock', 23),
('purchase.manage', 'Record purchases', 'Stock', 24),
('stocktake.manage', 'Run stocktaking', 'Stock', 25),
('customer.manage', 'Manage customers', 'Parties', 26),
('supplier.manage', 'Manage suppliers', 'Parties', 27),
('expense.manage', 'Record expenses', 'Money', 28),
('rate.manage', 'Change the exchange rate', 'Money', 29),
('report.sales', 'Sales reports', 'Reports', 30),
('report.profit', 'Profit reports', 'Reports', 31),
('report.stock', 'Stock reports', 'Reports', 32),
('report.cash', 'Cash and session reports', 'Reports', 33),
('user.manage', 'Manage users', 'Admin', 34),
('role.manage', 'Manage roles and permissions', 'Admin', 35),
('settings.manage', 'Change system settings', 'Admin', 36),
('register.manage', 'Manage POS registers', 'Admin', 37),
('backup.manage', 'Backups', 'Admin', 38),
('audit.view', 'View the audit log', 'Admin', 39);

INSERT INTO role_permissions (role_id, perm_key) VALUES
(2, 'pos.use'), (2, 'sale.create'), (2, 'sale.credit'), (2, 'sale.reprint'),
(2, 'return.create'), (2, 'debt.collect'), (2, 'session.open_own'), (2, 'session.close_own');

INSERT INTO settings (setting_key, setting_value) VALUES
('shop_name', 'Retail POS'),
('shop_address', ''),
('shop_phone', ''),
('receipt_header', ''),
('receipt_footer', 'Thank you for your visit!'),
('lbp_rounding_step', '5000'),
('max_cashier_discount_pct', '0'),
('usd_denominations', '100,50,20,10,5,1'),
('lbp_denominations', '100000,50000,20000,10000,5000,1000');

INSERT INTO counters (name, next_value) VALUES
('invoice', 1), ('return', 1), ('purchase', 1), ('session', 1), ('z', 1), ('count', 1);

INSERT INTO exchange_rates (lbp_per_usd) VALUES (90000);
