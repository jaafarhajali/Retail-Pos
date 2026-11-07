-- A role can be allowed to change its own password, or not (Aya, 2026-11-07): the owner ticks
-- "Change own password" per role on the Roles page. Every existing role keeps today's behaviour
-- (allowed) until the owner unticks it; Admin may always. A password reset marked "must change
-- at next sign-in" still works without the permission, so nobody is locked in with a password
-- the owner knows.
INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('account.password', 'Change own password', 'Admin', 40);

INSERT INTO role_permissions (role_id, perm_key)
SELECT id, 'account.password' FROM roles WHERE is_super = 0;
