CREATE TABLE IF NOT EXISTS tn_capital_market_states (
    organization_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    state_json JSON NOT NULL,
    trust_status VARCHAR(24) NOT NULL,
    quality_score TINYINT UNSIGNED NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    source_timestamp DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    last_sequence VARCHAR(190) NULL,
    last_event_fingerprint CHAR(64) NOT NULL,
    data_mode VARCHAR(24) NOT NULL,
    PRIMARY KEY (organization_id,venue_id,instrument_id),
    KEY ix_cm_market_state_trust (organization_id,trust_status,updated_at),
    CONSTRAINT fk_cm_market_state_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_state_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_state_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_market_state_score CHECK (quality_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_reference_states (
    organization_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    state_json JSON NOT NULL,
    trust_status VARCHAR(24) NOT NULL,
    quality_score TINYINT UNSIGNED NOT NULL,
    state_version BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    data_mode VARCHAR(24) NOT NULL,
    PRIMARY KEY (organization_id,source_id,instrument_id),
    KEY ix_cm_market_reference_trust (organization_id,trust_status,updated_at),
    CONSTRAINT fk_cm_market_reference_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_reference_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_market_reference_score CHECK (quality_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    snapshot_id VARCHAR(190) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    payload_json JSON NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_snapshot (organization_id,snapshot_id),
    KEY ix_cm_market_snapshot_time (organization_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_data_gaps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    gap_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    data_type VARCHAR(48) NOT NULL,
    gap_from DATETIME(6) NOT NULL,
    gap_to DATETIME(6) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    status VARCHAR(24) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_gap (organization_id,gap_id),
    KEY ix_cm_market_gap_status (organization_id,status,source_id),
    CONSTRAINT fk_cm_market_gap_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_gap_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_market_gap_window CHECK (gap_to >= gap_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cos_feature_flags
    (flag_key,description,enabled,rollout_percentage,rollout_salt)
VALUES
    ('capital_markets.market_data.enabled','Capital Markets Market Intelligence pipeline',1,100,'cm-mi-master'),
    ('capital_markets.market_data.streaming.enabled','Capital Markets streaming market-data runtime',0,0,'cm-mi-streaming-disabled'),
    ('capital_markets.market_data.history.enabled','Capital Markets market-data historical persistence',1,100,'cm-mi-history'),
    ('capital_markets.market_data.bybit.enabled','Capital Markets Bybit market-data adapter',0,0,'cm-mi-bybit-disabled'),
    ('capital_markets.market_data.massive.enabled','Capital Markets Massive reference-data adapter',0,0,'cm-mi-massive-disabled');

INSERT IGNORE INTO capital_market_user_capabilities
    (organization_id,user_id,capability,status,granted_by)
SELECT u.organization_id,u.id,c.capability,'ACTIVE','cm-market-intelligence-install'
FROM tn_users u
CROSS JOIN (
    SELECT 'capital_markets.market_data.view' capability UNION ALL
    SELECT 'capital_markets.market_data.manage' UNION ALL
    SELECT 'capital_markets.market_data.source.view' UNION ALL
    SELECT 'capital_markets.market_data.source.manage' UNION ALL
    SELECT 'capital_markets.market_data.quality.view' UNION ALL
    SELECT 'capital_markets.market_data.history.view' UNION ALL
    SELECT 'capital_markets.market_data.replay.manage'
) c
WHERE u.role='admin' AND u.status='active';

UPDATE cos_module_installations
SET installed_version='0.3.0',
    schema_version='0.3.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='capital_markets'
  AND status='INSTALLED'
  AND installed_version='0.2.0'
  AND schema_version='0.2.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000127_capital_markets_market_state');
