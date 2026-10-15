-- No source is auto-enabled by schema creation.
CREATE TABLE IF NOT EXISTS tn_capital_market_pool_external_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    subject_id VARCHAR(190) NOT NULL,
    document_url VARCHAR(800) NOT NULL,
    document_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    valid_until DATETIME(6) NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    reviewed_at DATETIME(6) NULL,
    review_reason VARCHAR(512) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(id),
    KEY ix_cm_pool_evidence_scope(organization_id,kind,subject_id,status,valid_until),
    CONSTRAINT chk_cm_pool_evidence_kind CHECK (kind IN
      ('SOURCE_ENTITLEMENT','ISSUER_IDENTITY')),
    CONSTRAINT chk_cm_pool_evidence_status CHECK (status IN ('PENDING','APPROVED','REJECTED')),
    CONSTRAINT chk_cm_pool_evidence_separation CHECK
      (reviewed_by IS NULL OR reviewed_by <> submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_pool_production_control (
    organization_id VARCHAR(190) NOT NULL PRIMARY KEY,
    phase VARCHAR(24) NOT NULL DEFAULT 'OFF',
    source_ids_json JSON NOT NULL,
    initially_disabled_json JSON NOT NULL,
    started_at DATETIME(6) NULL,
    soak_started_at DATETIME(6) NULL,
    accepted_at DATETIME(6) NULL,
    acceptance_digest CHAR(64) NULL,
    operator_id BIGINT UNSIGNED NOT NULL,
    last_reviewed_by BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_cm_pool_phase CHECK (phase IN
      ('OFF','CANARY','SOAK','ACCEPTED','LIVE','ROLLED_BACK'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operational shard assignments only. No shadow identity registry and no auto-subscribe.
CREATE TABLE IF NOT EXISTS tn_capital_market_pool_stream_assignments (
    organization_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    instrument_id VARCHAR(190) NOT NULL,
    kind VARCHAR(8) NOT NULL,
    shard_key VARCHAR(190) NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
        ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,source_id,instrument_id,kind),
    KEY ix_cm_pool_shard_assignment (organization_id,source_id,shard_key),
    CONSTRAINT fk_cm_pool_shard_source FOREIGN KEY (organization_id,source_id)
      REFERENCES tn_capital_market_data_sources(organization_id,source_id)
      ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT fk_cm_pool_shard_instrument FOREIGN KEY (organization_id,instrument_id)
      REFERENCES tn_capital_market_instruments(organization_id,instrument_id)
      ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT chk_cm_pool_shard_kind CHECK (kind IN ('BBO','L2'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable 5-minute receipts from a real tenant-scoped runtime watchdog.
-- Run epoch is bound to soak_started_at; old test/rollout receipts cannot be reused.
CREATE TABLE IF NOT EXISTS tn_capital_market_pool_soak_checkpoints (
    organization_id VARCHAR(190) NOT NULL,
    soak_started_at DATETIME(6) NOT NULL,
    bucket_at DATETIME(6) NOT NULL,
    healthy TINYINT(1) NOT NULL,
    evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,soak_started_at,bucket_at),
    CONSTRAINT chk_cm_pool_checkpoint_healthy CHECK (healthy IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time-range evidence reads must avoid unindexed whole-table scans.
ALTER TABLE tn_capital_market_canonical_events
    ADD INDEX ix_cm_pool_soak_window
      (organization_id,source_timestamp,source_id,instrument_id,event_type);

INSERT IGNORE INTO tn_migrations(migration)
VALUES('20261015_000144_capital_markets_pool_production');
