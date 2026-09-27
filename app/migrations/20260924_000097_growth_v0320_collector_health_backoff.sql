CREATE TABLE IF NOT EXISTS tn_growth_signal_collector_health (
    organization_id VARCHAR(64) NOT NULL,
    collector_name VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'healthy',
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_run_status VARCHAR(24) NULL,
    last_success_at DATETIME(6) NULL,
    last_failure_at DATETIME(6) NULL,
    next_retry_at DATETIME(6) NULL,
    error_summary VARCHAR(2000) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,collector_name),
    KEY ix_growth_collector_health_retry (status,next_retry_at),
    KEY ix_growth_collector_health_tenant (organization_id,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.32.0',
    schema_version='0.29.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.31.0'
  AND schema_version='0.28.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000097_growth_v0320_collector_health_backoff');
