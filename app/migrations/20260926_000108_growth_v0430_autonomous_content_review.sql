CREATE TABLE IF NOT EXISTS tn_growth_engagement_content_review_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    profile_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    email_mode VARCHAR(32) NOT NULL,
    linkedin_mode VARCHAR(32) NOT NULL,
    phone_mode VARCHAR(32) NOT NULL,
    min_draft_confidence DECIMAL(5,4) NOT NULL,
    max_body_chars INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_content_review_profile (organization_id,profile_id),
    UNIQUE KEY uq_growth_content_review_revision (organization_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_content_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    active_key VARCHAR(16) NULL,
    context_snapshot_json JSON NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    draft_id VARCHAR(80) NULL,
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
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_content_run (organization_id,run_id),
    UNIQUE KEY uq_growth_content_active_run (organization_id,recommendation_id,active_key),
    KEY ix_growth_content_run_recommendation (organization_id,recommendation_id,started_at),
    KEY ix_growth_content_run_status (organization_id,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_content_drafts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    draft_id VARCHAR(80) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    channel VARCHAR(40) NOT NULL,
    body TEXT NOT NULL,
    evidence_ids_json JSON NOT NULL,
    risk_flags_json JSON NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    provider VARCHAR(120) NOT NULL,
    model VARCHAR(191) NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    review_code VARCHAR(120) NOT NULL,
    decision_reason VARCHAR(1000) NULL,
    generated_by BIGINT UNSIGNED NOT NULL,
    decided_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL,
    decided_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_content_draft (organization_id,draft_id),
    UNIQUE KEY uq_growth_content_draft_revision (organization_id,recommendation_id,revision),
    UNIQUE KEY uq_growth_content_draft_run (organization_id,run_id),
    KEY ix_growth_content_draft_status (organization_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.43.0',
    schema_version='0.43.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.42.0'
  AND schema_version='0.42.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000108_growth_v0430_autonomous_content_review');
