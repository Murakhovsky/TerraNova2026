-- CM-PRODUCTION-STREAMING: shard lease and monotonic fencing.
-- Additive migration; does not enable live streaming.
CREATE TABLE IF NOT EXISTS tn_capital_market_stream_worker_leases (
  organization_id VARCHAR(190) NOT NULL,
  source_id VARCHAR(190) NOT NULL,
  shard_key VARCHAR(190) NOT NULL,
  owner_id VARCHAR(190) NOT NULL DEFAULT '',
  fencing_token BIGINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (organization_id,source_id,shard_key),
  INDEX idx_cm_stream_lease_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
