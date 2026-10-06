CREATE TABLE IF NOT EXISTS tn_capital_market_scan_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    target_id VARCHAR(190) NOT NULL,
    hypothesis VARCHAR(16) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    config_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_scan_target (organization_id,target_id),
    KEY ix_cm_scan_target_scheduler (enabled,organization_id,priority,target_id),
    CONSTRAINT chk_cm_scan_target_hypothesis CHECK (hypothesis IN ('H1','H2'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_scan_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    trigger VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL,
    target_count INT UNSIGNED NOT NULL DEFAULT 0,
    completed_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    result_json JSON NOT NULL,
    started_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_scan_run (organization_id,run_id),
    UNIQUE KEY uq_cm_scan_run_idempotency (organization_id,idempotency_key),
    KEY ix_cm_scan_run_time (organization_id,started_at),
    CONSTRAINT chk_cm_scan_run_status CHECK (status IN ('COMPLETED','PARTIAL','FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.6.0',
    schema_version='0.6.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.5.0'
  AND schema_version='0.5.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000130_capital_markets_tokenized_equity_scanner');
