CREATE TABLE IF NOT EXISTS tn_growth_handoff_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    attempt_id VARCHAR(80) NOT NULL,
    candidate_id VARCHAR(80) NOT NULL,
    target_domain VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    package_json JSON NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    target_reference_type VARCHAR(120) NULL,
    target_reference_id VARCHAR(191) NULL,
    reason VARCHAR(2000) NULL,
    error_summary VARCHAR(2000) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_handoff_attempt (organization_id,attempt_id),
    KEY ix_growth_handoff_candidate (organization_id,candidate_id,started_at),
    KEY ix_growth_handoff_running (organization_id,candidate_id,status,started_at),
    KEY ix_growth_handoff_target (organization_id,target_domain,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.8.0',
    schema_version='0.8.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.7.0'
  AND schema_version='0.7.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000073_growth_v080_handoff_protocol');
