CREATE TABLE IF NOT EXISTS sales_teams (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('ACTIVE','DISABLED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    assignment_mode ENUM('MANUAL','ROUND_ROBIN') NOT NULL DEFAULT 'MANUAL',
    configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by VARCHAR(191) NOT NULL,
    updated_by VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_teams_code (organization_id, code),
    KEY idx_sales_teams_status (organization_id, status, name),
    CONSTRAINT fk_sales_teams_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_team_members (
    organization_id VARCHAR(40) NOT NULL,
    team_id VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('MEMBER','LEAD') NOT NULL DEFAULT 'MEMBER',
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    assignment_enabled TINYINT(1) NOT NULL DEFAULT 1,
    approval_enabled TINYINT(1) NOT NULL DEFAULT 0,
    assignment_weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, team_id, user_id),
    KEY idx_sales_team_members_user (organization_id, user_id, status),
    KEY idx_sales_team_members_assignment (organization_id, team_id, status, assignment_enabled),
    KEY idx_sales_team_members_approval (organization_id, team_id, status, approval_enabled, role),
    CONSTRAINT fk_sales_team_members_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_team_members_team FOREIGN KEY (team_id) REFERENCES sales_teams (id),
    CONSTRAINT fk_sales_team_members_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_user_capabilities (
    organization_id VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(160) NOT NULL,
    status ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    granted_by VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, user_id, capability),
    KEY idx_sales_capability_lookup (organization_id, capability, status, user_id),
    CONSTRAINT fk_sales_capabilities_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_capabilities_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sales_teams
    (id, organization_id, code, name, status, assignment_mode, configuration_version, created_by, updated_by, created_at, updated_at)
SELECT CONCAT('sales-', LEFT(SHA2(o.id, 256), 32)), o.id, 'DEFAULT', 'Sales', 'ACTIVE', 'MANUAL', 1, 'system-v076', 'system-v076', NOW(6), NOW(6)
FROM cos_organizations o;

INSERT IGNORE INTO sales_team_members
    (organization_id, team_id, user_id, role, status, assignment_enabled, approval_enabled, assignment_weight, created_at, updated_at)
SELECT u.organization_id,
       CONCAT('sales-', LEFT(SHA2(u.organization_id, 256), 32)),
       u.id,
       CASE WHEN u.role IN ('admin','manager') THEN 'LEAD' ELSE 'MEMBER' END,
       'ACTIVE',
       1,
       CASE WHEN u.role IN ('admin','manager') THEN 1 ELSE 0 END,
       1,
       NOW(6), NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m
    ON m.organization_id = u.organization_id AND m.user_id = u.id AND m.status = 'ACTIVE'
WHERE u.status = 'active' AND u.role IN ('admin','manager','realtor');

INSERT IGNORE INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
SELECT u.organization_id,u.id,'sales.deal.assign','ACTIVE','system-v076',NOW(6),NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status='ACTIVE'
WHERE u.status='active' AND u.role IN ('admin','manager');

INSERT IGNORE INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
SELECT u.organization_id,u.id,'sales.approval.decide','ACTIVE','system-v076',NOW(6),NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status='ACTIVE'
WHERE u.status='active' AND u.role IN ('admin','manager');

INSERT IGNORE INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
SELECT u.organization_id,u.id,'sales.approval.any_team','ACTIVE','system-v076',NOW(6),NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status='ACTIVE'
WHERE u.status='active' AND u.role='admin';

INSERT IGNORE INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
SELECT u.organization_id,u.id,'sales.admin.teams.manage','ACTIVE','system-v076',NOW(6),NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status='ACTIVE'
WHERE u.status='active' AND u.role='admin';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260911_000034_sales_v076_users_teams_authority');
