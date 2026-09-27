CREATE TABLE IF NOT EXISTS tn_growth_optimization_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    context_snapshot_json JSON NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    recommendation_id VARCHAR(80) NULL,
    provider VARCHAR(120) NULL,
    model VARCHAR(191) NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cost_amount DECIMAL(18,8) NULL,
    cost_currency VARCHAR(16) NULL,
    error_summary VARCHAR(2000) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_optimization_run (organization_id,run_id),
    KEY ix_growth_optimization_run_status (organization_id,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_optimization_recommendations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id VARCHAR(80) NOT NULL,
    base_revision INT UNSIGNED NOT NULL,
    proposed_name VARCHAR(191) NOT NULL,
    proposed_criteria_json JSON NOT NULL,
    rationale TEXT NOT NULL,
    evidence_ids_json JSON NOT NULL,
    risks_json JSON NOT NULL,
    assumptions_json JSON NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    provider VARCHAR(120) NOT NULL,
    model VARCHAR(191) NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    decision_reason TEXT NULL,
    materialized_revision INT UNSIGNED NULL,
    decided_at DATETIME(6) NULL,
    materialized_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_optimization_recommendation (organization_id,recommendation_id),
    UNIQUE KEY uq_growth_optimization_recommendation_run (organization_id,run_id),
    KEY ix_growth_optimization_status (organization_id,status,updated_at),
    KEY ix_growth_optimization_target (organization_id,target_type,target_id,base_revision,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.17.0',
    schema_version='0.17.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.16.0'
  AND schema_version='0.15.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000081_growth_v0170_learning_optimization');
