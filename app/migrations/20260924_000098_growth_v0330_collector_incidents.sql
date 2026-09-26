CREATE TABLE IF NOT EXISTS tn_growth_signal_collector_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    incident_id VARCHAR(80) NOT NULL,
    collector_name VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    failure_count INT UNSIGNED NOT NULL,
    opened_at DATETIME(6) NOT NULL,
    last_failure_at DATETIME(6) NOT NULL,
    next_retry_at DATETIME(6) NULL,
    error_summary VARCHAR(2000) NOT NULL,
    resolved_at DATETIME(6) NULL,
    open_marker TINYINT GENERATED ALWAYS AS (CASE WHEN status='open' THEN 1 ELSE NULL END) STORED,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_collector_incident (organization_id,incident_id),
    UNIQUE KEY uq_growth_collector_open_incident (organization_id,collector_name,open_marker),
    KEY ix_growth_collector_incident_status (organization_id,status,opened_at),
    KEY ix_growth_collector_incident_retry (organization_id,status,next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.33.0',
    schema_version='0.30.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.32.0'
  AND schema_version='0.29.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260924_000098_growth_v0330_collector_incidents');
