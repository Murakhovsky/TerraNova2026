CREATE TABLE IF NOT EXISTS tn_capital_market_raw_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    raw_event_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NULL,
    external_instrument VARCHAR(190) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    provider_timestamp DATETIME(6) NULL,
    received_at DATETIME(6) NOT NULL,
    sequence_value VARCHAR(190) NULL,
    raw_payload_json JSON NOT NULL,
    transport_metadata_json JSON NOT NULL,
    data_mode VARCHAR(24) NOT NULL,
    retention_tier VARCHAR(24) NOT NULL DEFAULT 'HOT',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_raw_event (organization_id,raw_event_id),
    KEY ix_cm_market_raw_source_time (organization_id,source_id,received_at),
    KEY ix_cm_market_raw_symbol_time (organization_id,external_instrument,received_at),
    CONSTRAINT fk_cm_market_raw_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_raw_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_canonical_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    canonical_event_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NULL,
    instrument_id VARCHAR(190) NOT NULL,
    event_type VARCHAR(48) NOT NULL,
    source_timestamp DATETIME(6) NOT NULL,
    received_timestamp DATETIME(6) NOT NULL,
    processed_timestamp DATETIME(6) NOT NULL,
    sequence_value VARCHAR(190) NULL,
    payload_json JSON NOT NULL,
    quality_flags_json JSON NOT NULL,
    schema_version INT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    data_mode VARCHAR(24) NOT NULL,
    market_status VARCHAR(24) NOT NULL,
    retention_tier VARCHAR(24) NOT NULL DEFAULT 'WARM',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_canonical_event (organization_id,canonical_event_id),
    UNIQUE KEY uq_cm_market_canonical_fingerprint (organization_id,fingerprint),
    KEY ix_cm_market_canonical_instrument_time (organization_id,instrument_id,source_timestamp),
    KEY ix_cm_market_canonical_source_time (organization_id,source_id,source_timestamp),
    CONSTRAINT fk_cm_market_canonical_source FOREIGN KEY (organization_id,source_id)
        REFERENCES tn_capital_market_data_sources (organization_id,source_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_canonical_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_market_canonical_instrument FOREIGN KEY (organization_id,instrument_id)
        REFERENCES tn_capital_market_instruments (organization_id,instrument_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_quality_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    canonical_event_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    venue_id VARCHAR(190) NULL,
    instrument_id VARCHAR(190) NOT NULL,
    trust_status VARCHAR(24) NOT NULL,
    quality_score TINYINT UNSIGNED NOT NULL,
    flags_json JSON NOT NULL,
    ingestion_latency_ms BIGINT NOT NULL,
    processing_latency_ms BIGINT NOT NULL,
    event_age_ms BIGINT NOT NULL,
    reference_deviation_bps DECIMAL(38,12) NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cm_market_quality_event (organization_id,canonical_event_id),
    KEY ix_cm_market_quality_source_time (organization_id,source_id,recorded_at),
    KEY ix_cm_market_quality_instrument_time (organization_id,instrument_id,recorded_at),
    CONSTRAINT fk_cm_market_quality_event FOREIGN KEY (organization_id,canonical_event_id)
        REFERENCES tn_capital_market_canonical_events (organization_id,canonical_event_id)
        ON UPDATE RESTRICT ON DELETE CASCADE,
    CONSTRAINT chk_cm_market_quality_score CHECK (quality_score <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261006_000126_capital_markets_market_events');
