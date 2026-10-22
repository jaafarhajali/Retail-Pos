-- 011_attachments.sql (2026-10-22, Aya; the same as Retail Production's 020): the paper behind a record, photographed
-- or as a PDF. The supplier's invoice on a purchase, the receipt on an expense. Evidence, not a financial record: the
-- purchase or the expense stays append-only, the files live under public/uploads/attachments/ (in every backup, never
-- in git). Several files per record (front and back, a second page). Exactly one owner per row.
CREATE TABLE attachments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_id   INT UNSIGNED NULL,
  expense_id    INT UNSIGNED NULL,
  file          VARCHAR(80)  NOT NULL,                -- "p12-3f9a1c2e.jpg" / "e7-9a0b1c2d.pdf", under uploads/attachments/
  original_name VARCHAR(150) NOT NULL,                -- what the file was called on the phone or the PC
  mime          VARCHAR(60)  NOT NULL,                -- image/jpeg or application/pdf
  size_bytes    INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_att_purchase (purchase_id),
  KEY idx_att_expense (expense_id),
  CONSTRAINT fk_att_purchase FOREIGN KEY (purchase_id) REFERENCES purchases (id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_expense  FOREIGN KEY (expense_id)  REFERENCES expenses (id)  ON DELETE RESTRICT,
  CONSTRAINT fk_att_user     FOREIGN KEY (user_id)     REFERENCES users (id)     ON DELETE RESTRICT,
  CONSTRAINT chk_att_owner CHECK ((purchase_id IS NULL) <> (expense_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
