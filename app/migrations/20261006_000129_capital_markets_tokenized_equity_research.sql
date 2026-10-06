CREATE TABLE IF NOT EXISTS tn_capital_market_hypothesis_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    observation_id VARCHAR(190) NOT NULL,
    hypothesis VARCHAR(16) NOT NULL,
    stage VARCHAR(32) NOT NULL,
    market_pair_id VARCHAR(190) NULL,
    candidate_id VARCHAR(190) NULL,
    opportunity_id VARCHAR(190) NULL,
    execution_id VARCHAR(190) NULL,
    detected TINYINT(1) NOT NULL DEFAULT 0,
    executable TINYINT(1) NOT NULL DEFAULT 0,
    realized TINYINT(1) NOT NULL DEFAULT 0,
    expected_pnl DECIMAL(30,12) NOT NULL DEFAULT 0,
    realized_pnl DECIMAL(30,12) NOT NULL DEFAULT 0,
    reason VARCHAR(190) NULL,
    fingerprint CHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_hypothesis_observation (organization_id,observation_id),
    UNIQUE KEY uq_cm_hypothesis_fingerprint (organization_id,fingerprint),
    KEY ix_cm_hypothesis_stage (organization_id,hypothesis,stage,observed_at),
    KEY ix_cm_hypothesis_candidate (organization_id,candidate_id),
    KEY ix_cm_hypothesis_opportunity (organization_id,opportunity_id),
    CONSTRAINT chk_cm_hypothesis_stage CHECK (stage IN ('SCAN','EVALUATION','EXECUTION')),
    CONSTRAINT chk_cm_hypothesis_code CHECK (hypothesis IN ('H1','H2'))
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
