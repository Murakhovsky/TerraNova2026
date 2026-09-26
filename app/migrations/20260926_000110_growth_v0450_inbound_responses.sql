CREATE TABLE IF NOT EXISTS tn_growth_engagement_responses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    response_id VARCHAR(80) NOT NULL,
    source_event_id VARCHAR(191) NOT NULL,
    execution_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    recommendation_id VARCHAR(80) NOT NULL,
    action_id VARCHAR(80) NOT NULL,
    channel VARCHAR(40) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    body_hash CHAR(64) NOT NULL,
    provider_reference VARCHAR(191) NULL,
    thread_reference VARCHAR(191) NULL,
    occurred_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_engagement_response (organization_id,response_id),
    UNIQUE KEY uq_growth_engagement_response_source (organization_id,source_event_id),
    KEY ix_growth_engagement_response_candidate (organization_id,candidate_id,occurred_at),
    KEY ix_growth_engagement_response_execution (organization_id,execution_id,occurred_at),
    KEY ix_growth_engagement_response_action (organization_id,action_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_engagement_response_classifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    classification_id VARCHAR(80) NOT NULL,
    response_id VARCHAR(80) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    intent VARCHAR(40) NOT NULL,
    sentiment VARCHAR(40) NOT NULL,
    urgency VARCHAR(40) NOT NULL,
    summary VARCHAR(1000) NOT NULL,
    requested_action VARCHAR(1000) NULL,
    recommended_next_owner VARCHAR(40) NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    model VARCHAR(120) NOT NULL,
    prompt_version VARCHAR(120) NOT NULL,
    schema_version VARCHAR(120) NOT NULL,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    cost_amount DECIMAL(18,8) NULL,
    cost_currency VARCHAR(8) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_response_classification (organization_id,classification_id),
    UNIQUE KEY uq_growth_response_classification_revision (organization_id,response_id,revision),
    UNIQUE KEY uq_growth_response_classification_version (organization_id,response_id,prompt_version,schema_version),
    KEY ix_growth_response_classification_response (organization_id,response_id,revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.45.0',
    schema_version='0.45.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.44.0'
  AND schema_version='0.44.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260926_000110_growth_v0450_inbound_responses');
