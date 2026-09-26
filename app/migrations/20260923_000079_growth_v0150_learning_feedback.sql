CREATE TABLE IF NOT EXISTS tn_growth_learning_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    source_domain VARCHAR(80) NOT NULL,
    reference_type VARCHAR(80) NOT NULL,
    reference_id VARCHAR(191) NOT NULL,
    source_event_id VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_learning_binding_external (organization_id,source_domain,reference_type,reference_id),
    KEY ix_growth_learning_binding_candidate (organization_id,candidate_id,source_domain,created_at),
    KEY ix_growth_learning_binding_event (organization_id,source_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_outcomes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    outcome_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    source_domain VARCHAR(80) NOT NULL,
    source_event_id VARCHAR(80) NOT NULL,
    reference_type VARCHAR(80) NOT NULL,
    reference_id VARCHAR(191) NOT NULL,
    outcome_type VARCHAR(80) NOT NULL,
    reason_code VARCHAR(120) NULL,
    reason_text VARCHAR(2000) NULL,
    economic_value DECIMAL(18,4) NULL,
    currency VARCHAR(8) NULL,
    observed_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_outcome_id (organization_id,outcome_id),
    UNIQUE KEY uq_growth_outcome_source_event (organization_id,source_event_id),
    KEY ix_growth_outcome_candidate (organization_id,candidate_id,observed_at),
    KEY ix_growth_outcome_type (organization_id,outcome_type,observed_at),
    KEY ix_growth_outcome_reference (organization_id,source_domain,reference_type,reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.15.0',
    schema_version='0.15.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.14.0'
  AND schema_version='0.14.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000079_growth_v0150_learning_feedback');
