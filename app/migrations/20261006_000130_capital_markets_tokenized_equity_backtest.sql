CREATE TABLE IF NOT EXISTS tn_capital_market_backtest_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    hypothesis VARCHAR(16) NOT NULL,
    status VARCHAR(32) NOT NULL,
    dataset_hash CHAR(64) NOT NULL,
    from_at DATETIME(6) NOT NULL,
    to_at DATETIME(6) NOT NULL,
    snapshot_count INT UNSIGNED NOT NULL,
    train_count INT UNSIGNED NOT NULL,
    oos_count INT UNSIGNED NOT NULL,
    config_json JSON NOT NULL,
    summary_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_backtest_run (organization_id,run_id),
    KEY ix_cm_backtest_hypothesis_created (organization_id,hypothesis,created_at),
    KEY ix_cm_backtest_dataset (organization_id,dataset_hash),
    CONSTRAINT chk_cm_backtest_hypothesis CHECK (hypothesis IN ('H1','H2')),
    CONSTRAINT chk_cm_backtest_status CHECK (status IN ('COMPLETED'))
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
VALUES ('20261006_000130_capital_markets_tokenized_equity_backtest');
