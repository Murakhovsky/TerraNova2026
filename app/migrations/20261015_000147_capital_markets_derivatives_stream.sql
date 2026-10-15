-- Provider-authoritative contract status and source-fenced derivative feed evidence.
-- These are research observations, not trading permissions or contract identity overrides.
CREATE TABLE IF NOT EXISTS tn_capital_market_derivative_contract_status (
 organization_id VARCHAR(190) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 instrument_id VARCHAR(190) NOT NULL,
 provider_status VARCHAR(32) NOT NULL,
 contract_type VARCHAR(64) NOT NULL,
 tradable TINYINT(1) NOT NULL,
 observed_at DATETIME(6) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(organization_id,source_id,instrument_id),
 KEY ix_cm_derivatives_lifecycle(organization_id,source_id,tradable,observed_at),
 CONSTRAINT chk_cm_derivatives_tradable CHECK (tradable IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_derivative_contract_transitions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id VARCHAR(190) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 instrument_id VARCHAR(190) NOT NULL,
 old_status VARCHAR(32) NULL,
 new_status VARCHAR(32) NOT NULL,
 observed_at DATETIME(6) NOT NULL,
 evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 KEY ix_cm_derivative_transition(organization_id,source_id,instrument_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_derivative_stream_trust (
 organization_id VARCHAR(190) NOT NULL,
 source_id VARCHAR(190) NOT NULL,
 instrument_id VARCHAR(190) NOT NULL,
 data_type VARCHAR(32) NOT NULL,
 shard_key VARCHAR(190) NOT NULL,
 fencing_token BIGINT UNSIGNED NOT NULL,
 session_epoch INT UNSIGNED NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'UNTRUSTED',
 last_event_id VARCHAR(190) NULL,
 last_provider_at DATETIME(6) NULL,
 last_received_at DATETIME(6) NULL,
 quality_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
 accepted_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 failure_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
   ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,source_id,instrument_id,data_type),
 KEY ix_cm_derivative_stream_health(organization_id,source_id,status,last_received_at),
 CONSTRAINT chk_cm_derivative_stream_type CHECK
   (data_type IN ('FUNDING_RATE','MARK_PRICE','INDEX_PRICE','OPEN_INTEREST')),
 CONSTRAINT chk_cm_derivative_stream_state CHECK
   (status IN ('TRUSTED','STALE','UNTRUSTED')),
 CONSTRAINT chk_cm_derivative_stream_quality CHECK (quality_score<=100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations(migration)
VALUES ('20261015_000147_capital_markets_derivatives_stream');
