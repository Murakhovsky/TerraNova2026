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

-- Time-range evidence reads must avoid unindexed whole-table scans.
ALTER TABLE tn_capital_market_canonical_events
    ADD INDEX ix_cm_pool_soak_window
      (organization_id,source_timestamp,source_id,instrument_id,event_type);

INSERT IGNORE INTO tn_migrations(migration)
VALUES('20261015_000144_capital_markets_pool_production');
