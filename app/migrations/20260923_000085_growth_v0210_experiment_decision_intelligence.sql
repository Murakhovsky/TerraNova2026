CREATE TABLE IF NOT EXISTS tn_growth_experiment_decision_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    experiment_id VARCHAR(80) NOT NULL,
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
    UNIQUE KEY uq_growth_experiment_decision_run (organization_id,run_id),
    KEY ix_growth_experiment_decision_run_experiment (organization_id,experiment_id,started_at),
    KEY ix_growth_experiment_decision_run_status (organization_id,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_experiment_decision_recommendations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    experiment_id VARCHAR(80) NOT NULL,
    decision_type VARCHAR(40) NOT NULL,
    promoted_variant_key VARCHAR(64) NULL,
    rationale VARCHAR(2000) NOT NULL,
    evidence_ids_json JSON NOT NULL,
    risks_json JSON NOT NULL,
    assumptions_json JSON NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    provider VARCHAR(120) NOT NULL,
    model VARCHAR(191) NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    decision_reason VARCHAR(2000) NULL,
    decided_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_experiment_decision_recommendation (organization_id,recommendation_id),
    UNIQUE KEY uq_growth_experiment_decision_recommendation_run (organization_id,run_id),
    KEY ix_growth_experiment_decision_recommendation_experiment (organization_id,experiment_id,created_at),
    KEY ix_growth_experiment_decision_recommendation_status (organization_id,experiment_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.21.0',
    schema_version='0.21.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.20.0'
  AND schema_version='0.19.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000085_growth_v0210_experiment_decision_intelligence');
