UPDATE cos_integrations
SET status = 'DISABLED'
WHERE status = 'ERROR';

ALTER TABLE cos_integrations
    MODIFY COLUMN status ENUM('DRAFT','ACTIVE','DISABLED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    ADD COLUMN integration_key VARCHAR(120) NULL AFTER organization_id,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN health_status ENUM('UNKNOWN','HEALTHY','DEGRADED','ERROR') NOT NULL DEFAULT 'UNKNOWN' AFTER configuration_version,
    ADD COLUMN last_success_at DATETIME(6) NULL AFTER last_health_check_at,
    ADD COLUMN created_by VARCHAR(191) NULL AFTER last_error,
    ADD COLUMN updated_by VARCHAR(191) NULL AFTER created_by;

UPDATE cos_integrations
SET integration_key = CONCAT(LOWER(capability), '.', LOWER(provider))
WHERE integration_key IS NULL OR integration_key = '';

ALTER TABLE cos_integrations
    MODIFY COLUMN integration_key VARCHAR(120) NOT NULL,
    ADD UNIQUE KEY uq_cos_integrations_key (organization_id, integration_key),
    ADD KEY idx_cos_integrations_health (organization_id, health_status, last_health_check_at);

UPDATE cos_integrations
SET created_by = COALESCE(created_by, 'system-v077'),
    updated_by = COALESCE(updated_by, 'system-v077');

CREATE TABLE IF NOT EXISTS sales_integration_routes (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    integration_id BIGINT UNSIGNED NOT NULL,
    inbound_source VARCHAR(100) NOT NULL DEFAULT 'default',
    pipeline_id VARCHAR(40) NULL,
    initial_stage_id VARCHAR(40) NULL,
    team_id VARCHAR(40) NULL,
    assignment_strategy ENUM('KEEP_UNASSIGNED','TEAM_DEFAULT','ROUND_ROBIN') NOT NULL DEFAULT 'KEEP_UNASSIGNED',
    status ENUM('ACTIVE','DISABLED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by VARCHAR(191) NOT NULL,
    updated_by VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_integration_route_source (organization_id, integration_id, inbound_source),
    KEY idx_sales_integration_routes_runtime (organization_id, integration_id, status),
    KEY idx_sales_integration_routes_pipeline (organization_id, pipeline_id, initial_stage_id),
    KEY idx_sales_integration_routes_team (organization_id, team_id, status),
    CONSTRAINT fk_sales_integration_routes_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_integration_routes_integration FOREIGN KEY (integration_id) REFERENCES cos_integrations (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_integration_routes_pipeline FOREIGN KEY (pipeline_id) REFERENCES sales_pipelines (id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_integration_routes_stage FOREIGN KEY (initial_stage_id) REFERENCES sales_pipeline_stages (id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_integration_routes_team FOREIGN KEY (team_id) REFERENCES sales_teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
SELECT u.organization_id,u.id,'sales.admin.integrations.manage','ACTIVE','system-v077',NOW(6),NOW(6)
FROM tn_users u
INNER JOIN cos_organization_memberships m
    ON m.organization_id=u.organization_id AND m.user_id=u.id AND m.status='ACTIVE'
WHERE u.status='active' AND u.role='admin';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260911_000035_sales_v077_integrations_administration');
