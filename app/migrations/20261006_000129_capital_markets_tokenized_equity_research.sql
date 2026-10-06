CREATE TABLE IF NOT EXISTS tn_capital_market_hypothesis_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    observation_id VARCHAR(190) NOT NULL,
    hypothesis VARCHAR(16) NOT NULL,
    status VARCHAR(32) NOT NULL,
    candidate_count INT UNSIGNED NOT NULL DEFAULT 0,
    opportunity_count INT UNSIGNED NOT NULL DEFAULT 0,
    executable_count INT UNSIGNED NOT NULL DEFAULT 0,
    best_net_edge_bps DECIMAL(30,12) NULL,
    best_expected_pnl DECIMAL(30,12) NULL,
    observed_at DATETIME(6) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_hypothesis_observation (organization_id,observation_id),
    KEY ix_cm_hypothesis_observation_hypothesis (organization_id,hypothesis,observed_at),
    KEY ix_cm_hypothesis_observation_status (organization_id,hypothesis,status,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.5.0',
    schema_version='0.5.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.4.0'
  AND schema_version='0.4.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000129_capital_markets_tokenized_equity_research');
