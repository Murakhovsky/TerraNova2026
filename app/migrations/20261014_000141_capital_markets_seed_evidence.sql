-- Historical source evidence, not canonical Instrument identity.
CREATE TABLE IF NOT EXISTS tn_capital_market_seed_import_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id VARCHAR(190) NOT NULL,
  pool_key VARCHAR(80) NOT NULL,
  token_symbol VARCHAR(40) NOT NULL,
  underlying_symbol VARCHAR(40) NOT NULL,
  venue_symbol VARCHAR(80) NOT NULL,
  reported_as_of VARCHAR(40) NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  import_format VARCHAR(8) NOT NULL,
  row_evidence_json JSON NOT NULL,
  imported_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_cm_seed_org_pool_token (organization_id,pool_key,token_symbol),
  KEY idx_cm_seed_pool_updated (organization_id,pool_key,imported_at),
  KEY idx_cm_seed_file_hash (organization_id,file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tn_capital_market_seed_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id VARCHAR(190) NOT NULL,
  actor_id BIGINT UNSIGNED NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  source_as_of VARCHAR(40) NOT NULL,
  format VARCHAR(8) NOT NULL,
  total_rows INT UNSIGNED NOT NULL,
  new_rows INT UNSIGNED NOT NULL,
  changed_rows INT UNSIGNED NOT NULL,
  unchanged_rows INT UNSIGNED NOT NULL,
  imported_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_cm_seed_batch_hash (organization_id,file_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261014_000141_capital_markets_seed_evidence');
