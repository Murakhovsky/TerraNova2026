-- Append-only provenance for the existing canonical raw-event store.
-- Provider payload stays exclusively in tn_capital_market_raw_events;
-- replay must never publish to LIVE ingestion, execution or opportunity paths.
CREATE TABLE IF NOT EXISTS tn_capital_market_raw_provenance (
    organization_id VARCHAR(190) NOT NULL,
    raw_event_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    metadata_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    envelope_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    schema_version INT UNSIGNED NOT NULL DEFAULT 1,
    captured_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,raw_event_id),
    KEY ix_cm_provenance_source_time(organization_id,source_id,captured_at),
    CONSTRAINT fk_cm_raw_provenance_event FOREIGN KEY (organization_id,raw_event_id)
        REFERENCES tn_capital_market_raw_events(organization_id,raw_event_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable operator reconciliation receipts, no promotion of cross-venue
-- prices to source of truth and no trade/order effect.
CREATE TABLE IF NOT EXISTS tn_capital_market_venue_reconciliation (
    organization_id VARCHAR(190) NOT NULL,
    review_id CHAR(32) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    outcome VARCHAR(32) NOT NULL,
    evidence_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_ids_json JSON NOT NULL,
    observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,review_id),
    KEY ix_cm_venue_reconcile_instrument(organization_id,instrument_id,observed_at),
    CONSTRAINT chk_cm_venue_reconcile_outcome CHECK(outcome IN
        ('CONSENSUS','DIVERGED','NO_QUORUM','CURRENCY_MISMATCH'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avoid full instrument history scans when reconciling latest BBO
-- across independent sources, and keep subscriptions scoped by instrument.
-- MySQL 8 InnoDB online indexes: fail migration rather than force table lock.
ALTER TABLE tn_capital_market_canonical_events
 ADD INDEX ix_cm_cross_venue_latest
    (organization_id,instrument_id,source_id,event_type,received_timestamp,id),
 ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE tn_capital_market_subscriptions
 ADD INDEX ix_cm_reconcile_active_instrument
    (organization_id,instrument_id,data_type,status,source_id),
 ALGORITHM=INPLACE, LOCK=NONE;

INSERT IGNORE INTO tn_migrations(migration)
 VALUES ('20261015_000148_capital_markets_replay_scaling');
