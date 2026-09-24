-- 004_admin_closes_sessions.sql — owner decision 2026-09-24: cashiers do not close their own session; the admin counts and closes.
DELETE FROM role_permissions WHERE role_id = 2 AND perm_key = 'session.close_own';
