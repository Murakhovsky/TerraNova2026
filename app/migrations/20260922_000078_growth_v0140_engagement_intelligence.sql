CREATE TABLE IF NOT EXISTS tn_growth_engagement_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
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
    UNIQUE KEY uq_growth_engagement_run (organization_id,run_id),
    KEY ix_growth_engagement_run_candidate (organization_id,candidate_id,started_at),
    KEY ix_growth_engagement_run_status (organization_id,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_recommendations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    action_type VARCHAR(80) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    contact_id VARCHAR(80) NULL,
    rationale VARCHAR(2000) NOT NULL,
    message_angle VARCHAR(2000) NOT NULL,
    evidence_ids_json JSON NOT NULL,
    unknowns_json JSON NOT NULL,
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
    UNIQUE KEY uq_growth_engagement_recommendation (organization_id,recommendation_id),
    UNIQUE KEY uq_growth_engagement_recommendation_run (organization_id,run_id),
    KEY ix_growth_engagement_candidate (organization_id,candidate_id,created_at),
    KEY ix_growth_engagement_candidate_status (organization_id,candidate_id,status,updated_at),
    KEY ix_growth_engagement_action (organization_id,action_type,channel,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.14.0',
    schema_version='0.14.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.13.0'
  AND schema_version='0.8.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000078_growth_v0140_engagement_intelligence');
