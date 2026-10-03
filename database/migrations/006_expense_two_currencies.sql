-- 006_expense_two_currencies.sql — 2026-10-04 (Aya): one expense or supplier payment can be paid partly in USD and partly in LBP.
-- usd_paid and lbp_paid are what physically left (the drawer or the owner's pocket); amount_usd stays the total at the expense's rate.
ALTER TABLE expenses ADD COLUMN usd_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER description, ADD COLUMN lbp_paid DECIMAL(15,0) NOT NULL DEFAULT 0 AFTER usd_paid;
-- Older expenses had one amount in one currency: it moves to its column, nothing is lost.
UPDATE expenses SET usd_paid = CASE WHEN currency = 'USD' THEN amount ELSE 0 END, lbp_paid = CASE WHEN currency = 'LBP' THEN amount ELSE 0 END;
ALTER TABLE expenses DROP COLUMN amount, DROP COLUMN currency;
