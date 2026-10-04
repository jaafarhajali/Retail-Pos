-- 008_remove_void.sql — 2026-10-05 (Aya): voids are removed; a sale is undone with a return.
-- The permission goes from every role and from the list. Sales voided before keep their status and stay readable.
DELETE FROM role_permissions WHERE perm_key = 'sale.void';
DELETE FROM permissions WHERE perm_key = 'sale.void';
