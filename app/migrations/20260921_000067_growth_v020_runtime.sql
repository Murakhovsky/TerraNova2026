CREATE TABLE IF NOT EXISTS tn_growth_signals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    signal_id VARCHAR(80) NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id VARCHAR(191) NOT NULL,
    signal_type VARCHAR(120) NOT NULL,
    facts_json JSON NOT NULL,
    source_reference VARCHAR(500) NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    occurred_at DATETIME(6) NOT NULL,
    detected_at DATETIME(6) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_signal (organization_id,signal_id),
    KEY ix_growth_signal_subject (organization_id,subject_type,subject_id,occurred_at),
    KEY ix_growth_signal_type (organization_id,signal_type,detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    opportunity_type VARCHAR(80) NOT NULL,
    growth_mode VARCHAR(40) NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id VARCHAR(191) NOT NULL,
    target_domain VARCHAR(80) NOT NULL,
    status VARCHAR(40) NOT NULL,
    rationale_json JSON NULL,
    score_json JSON NULL,
    qualification_reason VARCHAR(2000) NULL,
    expected_value VARCHAR(500) NULL,
    recommended_play VARCHAR(191) NULL,
    recommended_action VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_candidate (organization_id,candidate_id),
    KEY ix_growth_candidate_subject (organization_id,subject_type,subject_id,status),
    KEY ix_growth_candidate_queue (organization_id,status,growth_mode,updated_at),
    KEY ix_growth_candidate_target (organization_id,target_domain,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_candidate_signals (
    organization_id VARCHAR(64) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    signal_id VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,candidate_id,signal_id),
    KEY ix_growth_candidate_signal_reverse (organization_id,signal_id,candidate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_operation_receipts (
    organization_id VARCHAR(64) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,operation_type,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.2.0',
    schema_version='0.2.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.1.0'
  AND schema_version='0.1.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260921_000067_growth_v020_runtime');
