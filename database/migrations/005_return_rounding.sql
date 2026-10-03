-- 005_return_rounding.sql — I4 (2026-10-03): a refund paid in LBP is rounded to 5,000 like change; the difference is kept on the return.
-- rounding_usd = refund value − what the refunds were worth (debt reduction + cash at the return's rate). +0.02 = the shop kept 2 cents.
ALTER TABLE returns ADD COLUMN rounding_usd DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_usd;
-- Returns made before this column: the same difference, from the refunds they already recorded.
UPDATE returns r SET r.rounding_usd = r.total_usd - (SELECT COALESCE(SUM(f.amount_usd), 0) FROM return_refunds f WHERE f.return_id = r.id);
