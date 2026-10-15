-- P0 provider receipts, operator incidents, and supervised failover evidence.
-- No credentials, raw provider packets or automatic live activation.
CREATE TABLE IF NOT EXISTS tn_capital_market_provider_stream_receipts (
 organization_id VARCHAR(190) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 instrument_id VARCHAR(190) NOT NULL,
 channel VARCHAR(8) NOT NULL,
 shard_key VARCHAR(190) NOT NULL,
 fencing_token BIGINT UNSIGNED NOT NULL,
 session_epoch BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(32) NOT NULL,
 status VARCHAR(16) NOT NULL,
 ack_at DATETIME(6) NOT NULL,
 last_live_quote_at DATETIME(6) NULL,
 ack_count BIGINT UNSIGNED NOT NULL DEFAULT 1,
 quote_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 last_quality_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
 last_error_code VARCHAR(100) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,source_id,instrument_id,channel),
 KEY ix_cm_provider_receipt_source(organization_id,source_id,status,last_live_quote_at),
 CONSTRAINT chk_cm_provider_receipt_state CHECK(status IN ('ACKED','LIVE_QUOTE','REVOKED')),
 CONSTRAINT chk_cm_provider_receipt_quality CHECK(last_quality_score<=100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_stream_incidents (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id VARCHAR(190) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 subject_id VARCHAR(190) NOT NULL DEFAULT '',
 code VARCHAR(100) NOT NULL,
 severity VARCHAR(16) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'OPEN',
 occurrences BIGINT UNSIGNED NOT NULL DEFAULT 1,
 first_seen_at DATETIME(6) NOT NULL,
 last_seen_at DATETIME(6) NOT NULL,
 resolved_at DATETIME(6) NULL,
 acknowledged_by BIGINT UNSIGNED NULL,
 acknowledged_at DATETIME(6) NULL,
 last_notification_at DATETIME(6) NULL,
 notification_attempts INT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_cm_stream_incident_scope(organization_id,source_id,subject_id,code),
 KEY ix_cm_stream_incidents_status(organization_id,status,last_seen_at),
 CONSTRAINT chk_cm_stream_incident_status CHECK(status IN ('OPEN','ACKNOWLEDGED','RESOLVED')),
 CONSTRAINT chk_cm_stream_incident_severity CHECK(severity IN ('CRITICAL','WARNING'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_stream_failover_observations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id VARCHAR(190) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 observation VARCHAR(40) NOT NULL,
 previous_token BIGINT UNSIGNED NULL,
 current_token BIGINT UNSIGNED NULL,
 healthy TINYINT(1) NOT NULL,
 evidence_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 KEY ix_cm_stream_failover_window(organization_id,source_id,observed_at),
 CONSTRAINT chk_cm_stream_failover_healthy CHECK(healthy IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations(migration)
 VALUES ('20261015_000146_capital_markets_provider_ops');
