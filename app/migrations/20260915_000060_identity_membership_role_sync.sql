-- Identity authority repair: Workspace authorization is owned by organization membership.
-- Legacy administration and create-admin paths historically updated tn_users.role/status only,
-- which could leave the home membership missing or stale and produce a false 403 on /admin.

INSERT INTO cos_organization_memberships (
    organization_id,
    user_id,
    role,
    status
)
SELECT
    u.organization_id,
    u.id,
    u.role,
    CASE u.status
        WHEN 'active' THEN 'ACTIVE'
        WHEN 'pending' THEN 'INVITED'
        WHEN 'blocked' THEN 'SUSPENDED'
        ELSE 'SUSPENDED'
    END
FROM tn_users u
ON DUPLICATE KEY UPDATE
    role = VALUES(role),
    status = VALUES(status),
    updated_at = CURRENT_TIMESTAMP(6);

INSERT INTO tn_migrations (migration)
VALUES ('20260915_000060_identity_membership_role_sync');
