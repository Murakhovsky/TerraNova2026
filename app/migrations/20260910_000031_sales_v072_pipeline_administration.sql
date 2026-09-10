ALTER TABLE sales_pipelines
    MODIFY COLUMN status ENUM('DRAFT','ACTIVE','DISABLED','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    ADD COLUMN initial_stage_id VARCHAR(40) NULL AFTER is_default,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD KEY idx_sales_pipeline_initial_stage (organization_id, initial_stage_id);

ALTER TABLE sales_pipeline_stages
    ADD COLUMN status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE' AFTER probability_default,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD KEY idx_sales_stage_runtime (organization_id, pipeline_id, status, sort_order);

UPDATE sales_pipelines p
SET initial_stage_id = (
    SELECT s.id FROM sales_pipeline_stages s
    WHERE s.pipeline_id = p.id AND s.organization_id = p.organization_id AND s.is_terminal = 0
    ORDER BY s.sort_order, s.id LIMIT 1
)
WHERE p.initial_stage_id IS NULL;

CREATE TABLE IF NOT EXISTS sales_lost_reasons (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    pipeline_id VARCHAR(40) NOT NULL,
    code VARCHAR(100) NOT NULL,
    name VARCHAR(180) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_sales_lost_reason_code (pipeline_id, code),
    KEY idx_sales_lost_reason_runtime (organization_id, pipeline_id, status, sort_order),
    CONSTRAINT fk_sales_lost_reason_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_sales_lost_reason_pipeline FOREIGN KEY (pipeline_id) REFERENCES sales_pipelines (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tn_client_cases
    ADD COLUMN lost_reason_id VARCHAR(40) NULL AFTER lost_reason,
    ADD COLUMN lost_reason_note TEXT NULL AFTER lost_reason_id,
    ADD KEY idx_tn_cases_lost_reason (organization_id, lost_reason_id),
    ADD CONSTRAINT fk_tn_cases_lost_reason FOREIGN KEY (lost_reason_id) REFERENCES sales_lost_reasons (id) ON DELETE SET NULL;

INSERT INTO sales_lost_reasons
    (id, organization_id, pipeline_id, code, name, sort_order, status, configuration_version)
SELECT LEFT(SHA2(CONCAT(p.id, ':lost:other'), 256), 32), p.organization_id, p.id,
       'OTHER', 'Інше', 900, 'ACTIVE', 1
FROM sales_pipelines p
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'ACTIVE';

-- V0.6 seed connected adjacent stages and produced WON -> LOST. Terminal stages are exits.
DELETE transition
FROM sales_pipeline_transitions transition
INNER JOIN sales_pipeline_stages source_stage ON source_stage.id = transition.from_stage_id
WHERE source_stage.is_terminal = 1;

-- Deals may be lost from any active non-terminal stage.
INSERT INTO sales_pipeline_transitions
    (id, organization_id, pipeline_id, from_stage_id, to_stage_id, requires_approval, conditions)
SELECT LEFT(SHA2(CONCAT(source_stage.pipeline_id, ':', source_stage.code, ':', lost_stage.code), 256), 32),
       source_stage.organization_id, source_stage.pipeline_id, source_stage.id, lost_stage.id, 0, JSON_ARRAY()
FROM sales_pipeline_stages source_stage
INNER JOIN sales_pipeline_stages lost_stage
    ON lost_stage.pipeline_id = source_stage.pipeline_id
    AND lost_stage.organization_id = source_stage.organization_id
    AND lost_stage.is_lost = 1
    AND lost_stage.status = 'ACTIVE'
WHERE source_stage.is_terminal = 0 AND source_stage.status = 'ACTIVE'
ON DUPLICATE KEY UPDATE requires_approval = VALUES(requires_approval);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260910_000031_sales_v072_pipeline_administration');
