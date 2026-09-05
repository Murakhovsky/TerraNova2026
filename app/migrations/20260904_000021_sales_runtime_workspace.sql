CREATE TABLE IF NOT EXISTS sales_pipelines (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(180) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('ACTIVE', 'DISABLED', 'ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_pipeline_code (organization_id, code),
    KEY idx_sales_pipeline_active (organization_id, status, is_default),
    CONSTRAINT fk_sales_pipeline_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_pipeline_stages (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    pipeline_id VARCHAR(40) NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(180) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
    is_won TINYINT(1) NOT NULL DEFAULT 0,
    is_lost TINYINT(1) NOT NULL DEFAULT 0,
    probability_default DECIMAL(5,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_stage_code (pipeline_id, code),
    KEY idx_sales_stage_order (organization_id, pipeline_id, sort_order),
    CONSTRAINT fk_sales_stage_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_stage_pipeline FOREIGN KEY (pipeline_id) REFERENCES sales_pipelines (id) ON DELETE CASCADE,
    CONSTRAINT chk_sales_stage_probability CHECK (probability_default >= 0 AND probability_default <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_pipeline_transitions (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    pipeline_id VARCHAR(40) NOT NULL,
    from_stage_id VARCHAR(40) NOT NULL,
    to_stage_id VARCHAR(40) NOT NULL,
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    conditions JSON NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_transition (pipeline_id, from_stage_id, to_stage_id),
    KEY idx_sales_transition_from (organization_id, pipeline_id, from_stage_id),
    CONSTRAINT fk_sales_transition_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_transition_pipeline FOREIGN KEY (pipeline_id) REFERENCES sales_pipelines (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_transition_from FOREIGN KEY (from_stage_id) REFERENCES sales_pipeline_stages (id) ON DELETE CASCADE,
    CONSTRAINT fk_sales_transition_to FOREIGN KEY (to_stage_id) REFERENCES sales_pipeline_stages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_communications (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    deal_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NULL,
    activity_id BIGINT UNSIGNED NULL,
    channel ENUM('TELEGRAM', 'EMAIL', 'PHONE', 'WEB', 'WHATSAPP', 'VIBER') NOT NULL,
    direction ENUM('INBOUND', 'OUTBOUND') NOT NULL,
    sender VARCHAR(255) NULL,
    recipient VARCHAR(255) NULL,
    body TEXT NULL,
    external_id VARCHAR(191) NULL,
    metadata JSON NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_communication_external (organization_id, channel, external_id),
    KEY idx_sales_communication_deal (organization_id, deal_id, occurred_at),
    KEY idx_sales_communication_person (organization_id, person_id, occurred_at),
    CONSTRAINT fk_sales_communication_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_communication_deal FOREIGN KEY (deal_id) REFERENCES tn_client_cases (id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_communication_person FOREIGN KEY (person_id) REFERENCES tn_people (id) ON DELETE SET NULL,
    CONSTRAINT fk_sales_communication_activity FOREIGN KEY (activity_id) REFERENCES tn_client_case_activities (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cos_action_outcomes (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    action_id VARCHAR(40) NOT NULL,
    metric VARCHAR(160) NOT NULL,
    value JSON NOT NULL,
    attribution_type ENUM('DIRECT', 'ASSISTED', 'INFERRED', 'MANUAL') NOT NULL,
    evidence JSON NULL,
    measured_at DATETIME(6) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_action_outcome (action_id, metric, measured_at),
    KEY idx_cos_action_outcomes_org_metric (organization_id, metric, measured_at),
    CONSTRAINT fk_cos_action_outcome_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_cos_action_outcome_action FOREIGN KEY (action_id) REFERENCES cos_actions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_metric_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NOT NULL,
    metric VARCHAR(160) NOT NULL,
    value DECIMAL(20,6) NOT NULL,
    dimensions JSON NULL,
    period_start DATETIME(6) NOT NULL,
    period_end DATETIME(6) NOT NULL,
    calculated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_metric_period (organization_id, metric, period_start, period_end),
    KEY idx_sales_metric_org_time (organization_id, metric, period_end),
    CONSTRAINT fk_sales_metric_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tn_client_cases
    ADD COLUMN pipeline_id VARCHAR(40) NULL AFTER inbound_request_id,
    ADD COLUMN stage_id VARCHAR(40) NULL AFTER pipeline_id,
    ADD COLUMN deal_value DECIMAL(14,2) NULL AFTER budget_max,
    ADD COLUMN probability DECIMAL(5,2) NULL AFTER deal_value,
    ADD COLUMN last_activity_at DATETIME(6) NULL AFTER next_contact_at,
    ADD COLUMN expected_close_at DATETIME(6) NULL AFTER last_activity_at,
    ADD COLUMN won_reason TEXT NULL AFTER closed_at,
    ADD COLUMN lost_reason TEXT NULL AFTER won_reason,
    ADD KEY idx_tn_cases_org_pipeline_stage (organization_id, pipeline_id, stage_id),
    ADD KEY idx_tn_cases_org_next_action (organization_id, status, next_contact_at),
    ADD CONSTRAINT fk_tn_cases_pipeline FOREIGN KEY (pipeline_id) REFERENCES sales_pipelines (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_tn_cases_stage FOREIGN KEY (stage_id) REFERENCES sales_pipeline_stages (id) ON DELETE SET NULL,
    ADD CONSTRAINT chk_tn_cases_probability CHECK (probability IS NULL OR (probability >= 0 AND probability <= 100));

INSERT INTO sales_pipelines (id, organization_id, code, name, is_default, status)
SELECT CONCAT('pipeline-', LEFT(SHA2(CONCAT(id, ':default-sales'), 256), 23)), id, 'default-sales', 'Default Sales', 1, 'ACTIVE'
FROM cos_organizations
ON DUPLICATE KEY UPDATE name = VALUES(name), is_default = 1, status = 'ACTIVE';

INSERT INTO sales_pipeline_stages
    (id, organization_id, pipeline_id, code, name, sort_order, is_terminal, is_won, is_lost, probability_default)
SELECT LEFT(SHA2(CONCAT(p.id, ':', seed.code), 256), 32), p.organization_id, p.id, seed.code, seed.name,
       seed.sort_order, seed.is_terminal, seed.is_won, seed.is_lost, seed.probability_default
FROM sales_pipelines p
JOIN (
    SELECT 'NEW' code, 'New' name, 10 sort_order, 0 is_terminal, 0 is_won, 0 is_lost, 5 probability_default UNION ALL
    SELECT 'CONTACTED', 'Contacted', 20, 0, 0, 0, 10 UNION ALL
    SELECT 'QUALIFIED', 'Qualified', 30, 0, 0, 0, 25 UNION ALL
    SELECT 'MEETING', 'Meeting', 40, 0, 0, 0, 40 UNION ALL
    SELECT 'PROPOSAL', 'Proposal', 50, 0, 0, 0, 60 UNION ALL
    SELECT 'NEGOTIATION', 'Negotiation', 60, 0, 0, 0, 75 UNION ALL
    SELECT 'WON', 'Won', 70, 1, 1, 0, 100 UNION ALL
    SELECT 'LOST', 'Lost', 80, 1, 0, 1, 0
) seed
WHERE p.code = 'default-sales'
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order),
    is_terminal = VALUES(is_terminal), is_won = VALUES(is_won), is_lost = VALUES(is_lost),
    probability_default = VALUES(probability_default);

INSERT INTO sales_pipeline_transitions
    (id, organization_id, pipeline_id, from_stage_id, to_stage_id, requires_approval, conditions)
SELECT LEFT(SHA2(CONCAT(a.pipeline_id, ':', a.code, ':', b.code), 256), 32), a.organization_id, a.pipeline_id,
       a.id, b.id, 0, JSON_ARRAY()
FROM sales_pipeline_stages a
INNER JOIN sales_pipeline_stages b ON b.pipeline_id = a.pipeline_id AND b.sort_order = a.sort_order + 10
ON DUPLICATE KEY UPDATE requires_approval = VALUES(requires_approval), conditions = VALUES(conditions);

UPDATE tn_client_cases c
JOIN sales_pipelines p ON p.organization_id = c.organization_id AND p.code = 'default-sales'
LEFT JOIN sales_pipeline_stages s ON s.pipeline_id = p.id AND s.code = CASE c.stage
    WHEN 'new' THEN 'NEW' WHEN 'qualification' THEN 'QUALIFIED' WHEN 'need_defined' THEN 'QUALIFIED'
    WHEN 'matching' THEN 'PROPOSAL' WHEN 'viewing' THEN 'MEETING' WHEN 'negotiation' THEN 'NEGOTIATION'
    WHEN 'deal' THEN 'WON' WHEN 'aftercare' THEN 'WON' WHEN 'repeat' THEN 'CONTACTED'
    WHEN 'lost' THEN 'LOST' ELSE 'CONTACTED' END
SET c.pipeline_id = p.id, c.stage_id = s.id,
    c.probability = COALESCE(c.probability, s.probability_default),
    c.deal_value = COALESCE(c.deal_value, c.budget_max),
    c.last_activity_at = COALESCE(c.last_activity_at, c.updated_at)
WHERE c.pipeline_id IS NULL OR c.stage_id IS NULL;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260904_000021_sales_runtime_workspace');
