ALTER TABLE cos_configuration_revisions
    MODIFY COLUMN configuration_type ENUM('RULE','POLICY','PIPELINE','STAGE','TRANSITION','LOST_REASON','AGENT','TEAM','ASSIGNMENT','INTEGRATION','INTEGRATION_ROUTE') NOT NULL,
    MODIFY COLUMN action ENUM('CREATE','UPDATE','ENABLE','DISABLE','ACTIVATE','ARCHIVE','ROLLBACK','PROVISION') NOT NULL;

INSERT IGNORE INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
SELECT u.organization_id,u.id,'sales.admin.audit.view','ACTIVE','system-v078',NOW(6),NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status='ACTIVE'
WHERE u.status='active' AND u.role='admin';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260911_000036_sales_v078_admin_closure');
