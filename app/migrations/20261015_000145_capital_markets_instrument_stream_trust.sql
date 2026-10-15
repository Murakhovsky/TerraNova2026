-- Per-instrument canonical source/channel trust, independently fenced.
CREATE TABLE IF NOT EXISTS tn_capital_market_instrument_stream_trust (
  organization_id VARCHAR(190) NOT NULL,
  source_id VARCHAR(190) NOT NULL,
  instrument_id VARCHAR(190) NOT NULL,
  channel VARCHAR(8) NOT NULL,
  shard_key VARCHAR(190) NOT NULL DEFAULT '',
  status VARCHAR(16) NOT NULL DEFAULT 'UNTRUSTED',
  fencing_token BIGINT UNSIGNED NOT NULL,
  last_sequence VARCHAR(190) NULL,
  last_event_at DATETIME(6) NULL,
  last_quality_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
  recovery_required TINYINT UNSIGNED NOT NULL DEFAULT 1,
  reason VARCHAR(100) NOT NULL DEFAULT 'NOT_OBSERVED',
  accepted_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
    ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (organization_id,source_id,instrument_id,channel),
  KEY ix_cm_stream_trust_health(organization_id,source_id,status,updated_at),
  CONSTRAINT chk_cm_stream_trust_channel CHECK (channel IN ('BBO','L2')),
  CONSTRAINT chk_cm_stream_trust_status CHECK (status IN ('TRUSTED','UNTRUSTED','STALE')),
  CONSTRAINT chk_cm_stream_trust_quality CHECK (last_quality_score<=100),
  CONSTRAINT chk_cm_stream_trust_recovery CHECK (recovery_required IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations(migration)
VALUES('20261015_000145_capital_markets_instrument_stream_trust');
