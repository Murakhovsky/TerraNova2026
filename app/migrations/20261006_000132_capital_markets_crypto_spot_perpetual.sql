ALTER TABLE tn_capital_market_hypothesis_observations
    DROP CHECK chk_cm_hypothesis_code;

ALTER TABLE tn_capital_market_hypothesis_observations
    ADD CONSTRAINT chk_cm_hypothesis_code
    CHECK (hypothesis IN ('H1','H2','H4','H5','H6'));

CREATE TABLE IF NOT EXISTS tn_capital_market_funding_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    observation_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    rate DECIMAL(30,18) NOT NULL,
    rate_type VARCHAR(48) NOT NULL,
    status VARCHAR(24) NOT NULL,
    observation_at DATETIME(6) NOT NULL,
    next_settlement_at DATETIME(6) NULL,
    funding_interval_seconds INT UNSIGNED NOT NULL,
    cap DECIMAL(30,18) NULL,
    floor DECIMAL(30,18) NULL,
    quality TINYINT UNSIGNED NOT NULL,
    source VARCHAR(190) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_funding_observation (organization_id,observation_id),
    KEY ix_cm_funding_series (organization_id,venue_id,instrument_id,observation_at),
    KEY ix_cm_funding_settlement (organization_id,next_settlement_at),
    CONSTRAINT chk_cm_funding_quality CHECK (quality <= 100),
    CONSTRAINT chk_cm_funding_interval CHECK (funding_interval_seconds >= 60),
    CONSTRAINT chk_cm_funding_status CHECK (status IN ('ESTIMATED','CURRENT','SETTLED','UNKNOWN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_basis_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    observation_id VARCHAR(190) NOT NULL,
    market_pair_id VARCHAR(190) NOT NULL,
    spot_market VARCHAR(190) NOT NULL,
    perpetual_market VARCHAR(190) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    spot_bid DECIMAL(30,12) NOT NULL,
    spot_ask DECIMAL(30,12) NOT NULL,
    spot_mid DECIMAL(30,12) NOT NULL,
    perp_bid DECIMAL(30,12) NOT NULL,
    perp_ask DECIMAL(30,12) NOT NULL,
    perp_mid DECIMAL(30,12) NOT NULL,
    mark_price DECIMAL(30,12) NULL,
    index_price DECIMAL(30,12) NULL,
    mid_basis_absolute DECIMAL(30,12) NOT NULL,
    mid_basis_bps DECIMAL(30,12) NOT NULL,
    long_spot_short_perp_basis DECIMAL(30,12) NOT NULL,
    short_spot_long_perp_basis DECIMAL(30,12) NOT NULL,
    quality TINYINT UNSIGNED NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_basis_observation (organization_id,observation_id),
    KEY ix_cm_basis_series (organization_id,market_pair_id,observed_at),
    CONSTRAINT chk_cm_basis_quality CHECK (quality <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_funding_settlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    settlement_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    position_reference VARCHAR(190) NOT NULL,
    settlement_at DATETIME(6) NOT NULL,
    rate DECIMAL(30,18) NOT NULL,
    position_notional DECIMAL(30,12) NOT NULL,
    side VARCHAR(8) NOT NULL,
    gross_cashflow DECIMAL(30,12) NOT NULL,
    currency VARCHAR(32) NOT NULL,
    source VARCHAR(190) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_funding_settlement (organization_id,settlement_id),
    KEY ix_cm_funding_position (organization_id,position_reference,settlement_at),
    KEY ix_cm_funding_instrument_settlement (organization_id,venue_id,instrument_id,settlement_at),
    CONSTRAINT chk_cm_funding_settlement_side CHECK (side IN ('LONG','SHORT')),
    CONSTRAINT chk_cm_funding_settlement_notional CHECK (position_notional >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_hedge_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    hedge_group_id VARCHAR(190) NOT NULL,
    strategy_version VARCHAR(190) NOT NULL,
    opportunity_id VARCHAR(190) NULL,
    execution_id VARCHAR(190) NULL,
    state VARCHAR(32) NOT NULL,
    target_delta DECIMAL(30,12) NOT NULL,
    actual_delta DECIMAL(30,12) NOT NULL,
    allowed_tolerance DECIMAL(30,12) NOT NULL,
    payload_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_hedge_group (organization_id,hedge_group_id),
    KEY ix_cm_hedge_group_opportunity (organization_id,opportunity_id),
    KEY ix_cm_hedge_group_execution (organization_id,execution_id),
    KEY ix_cm_hedge_group_state (organization_id,state,updated_at),
    CONSTRAINT chk_cm_hedge_tolerance CHECK (allowed_tolerance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cos_feature_flags
    (flag_key,description,enabled,rollout_percentage,rollout_salt)
VALUES
    ('capital_markets.crypto_spot_perpetual.enabled','Capital Markets Crypto Spot/Perpetual H4/H5/H6 research and paper vertical slice',1,100,'cm-crypto-spot-perp-v1'),
    ('capital_markets.market_data.okx.enabled','Capital Markets OKX public market-data adapter',1,100,'cm-okx-market-data-v1');

UPDATE cos_module_installations
SET installed_version='0.7.0',
    schema_version='0.7.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.6.0'
  AND schema_version='0.6.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000132_capital_markets_crypto_spot_perpetual');
