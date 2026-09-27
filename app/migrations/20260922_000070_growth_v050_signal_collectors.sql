CREATE TABLE IF NOT EXISTS tn_growth_signal_collector_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    run_id VARCHAR(80) NOT NULL,
    collector_name VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    request_cursor VARCHAR(1000) NULL,
    requested_limit INT UNSIGNED NOT NULL,
    next_cursor VARCHAR(1000) NULL,
    collected_count INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_summary VARCHAR(2000) NULL,
    started_at DATETIME(6) NOT NULL,
    finished_at DATETIME(6) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_collector_run (organization_id,run_id),
    KEY ix_growth_collector_status (organization_id,collector_name,status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_growth_signal_source_receipts (
    organization_id VARCHAR(64) NOT NULL,
    collector_name VARCHAR(120) NOT NULL,
    external_key_hash CHAR(64) NOT NULL,
    external_key VARCHAR(500) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    signal_id VARCHAR(80) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,collector_name,external_key_hash),
    KEY ix_growth_source_signal (organization_id,signal_id),
    KEY ix_growth_source_created (organization_id,collector_name,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.5.0',
    schema_version='0.5.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.4.0'
  AND schema_version='0.4.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260922_000070_growth_v050_signal_collectors');
