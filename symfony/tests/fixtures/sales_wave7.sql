-- Sales Wave 7 Frontend cutover fixture.
-- Extends the accumulated Symfony test schema with the Sales authority tables
-- required by capability-aware administration endpoints.

ALTER TABLE tn_users
    ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'realtor' AFTER full_name;

UPDATE tn_users u
INNER JOIN cos_organization_memberships m
    ON m.organization_id=u.organization_id AND m.user_id=u.id
SET u.role=LOWER(m.role);

ALTER TABLE sales_teams
    ADD COLUMN assignment_mode VARCHAR(32) NOT NULL DEFAULT 'MANUAL' AFTER status,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER assignment_mode,
    ADD COLUMN created_by VARCHAR(191) NOT NULL DEFAULT 'wave7-fixture' AFTER configuration_version,
    ADD COLUMN updated_by VARCHAR(191) NOT NULL DEFAULT 'wave7-fixture' AFTER created_by,
    ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER updated_by,
    ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER created_at;

CREATE TABLE sales_team_members (
    organization_id VARCHAR(190) NOT NULL,
    team_id VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'MEMBER',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    assignment_enabled TINYINT(1) NOT NULL DEFAULT 1,
    approval_enabled TINYINT(1) NOT NULL DEFAULT 0,
    assignment_weight SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id,team_id,user_id)
);

CREATE TABLE sales_user_capabilities (
    organization_id VARCHAR(190) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    capability VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    granted_by VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id,user_id,capability)
);

INSERT INTO sales_team_members
    (organization_id,team_id,user_id,role,status,assignment_enabled,approval_enabled,assignment_weight,created_at,updated_at)
SELECT 'default','team-default-sales',1001,'LEAD','ACTIVE',1,1,1,NOW(6),NOW(6)
WHERE EXISTS (SELECT 1 FROM sales_teams WHERE id='team-default-sales');

INSERT INTO sales_user_capabilities
    (organization_id,user_id,capability,status,granted_by,created_at,updated_at)
VALUES
    ('default',1001,'sales.admin.pipeline.manage','ACTIVE','wave7-fixture',NOW(6),NOW(6));


-- Wave 7 exercises the operational Sales projection introduced after the
-- original Wave 3 fixture. Production V0.8.3 added these compatibility fields.
ALTER TABLE sales_deal_stage_history
    ADD COLUMN stage_id BIGINT UNSIGNED NULL AFTER projected_at,
    ADD COLUMN is_backfill TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_id;

UPDATE sales_deal_stage_history
SET stage_id = to_stage_id
WHERE stage_id IS NULL;


-- Wave 1 used a deliberately tiny Action Outcome table. The canonical runtime
-- workspace schema links every outcome to the Action and records metric provenance.
ALTER TABLE cos_action_outcomes
    ADD COLUMN action_id VARCHAR(64) NULL AFTER organization_id,
    ADD COLUMN metric VARCHAR(160) NULL AFTER action_id,
    ADD COLUMN value JSON NULL AFTER metric,
    ADD COLUMN attribution_type VARCHAR(32) NULL AFTER value,
    ADD COLUMN evidence JSON NULL AFTER attribution_type,
    ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER measured_at;

UPDATE cos_action_outcomes
SET action_id = 'action-1',
    metric = 'fixture.outcome',
    value = JSON_OBJECT('fixture', true),
    attribution_type = 'MANUAL'
WHERE action_id IS NULL;
