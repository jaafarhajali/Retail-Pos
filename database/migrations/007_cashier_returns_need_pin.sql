-- 007_cashier_returns_need_pin.sql — 2026-10-04 (Aya): a cashier needs the administrator's PIN to make a return.
-- The Cashier role no longer holds return.create, so the till asks for the PIN (spec §15 PIN override). A role given it in Roles needs none.
DELETE FROM role_permissions WHERE role_id = 2 AND perm_key = 'return.create';
