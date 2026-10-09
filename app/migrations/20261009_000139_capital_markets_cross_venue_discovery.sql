-- Provider discovery results are historical evidence, never authoritative trading mappings.
CREATE TABLE IF NOT EXISTS tn_capital_market_cross_venue_discovery_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    universe_id VARCHAR(120) NOT NULL,
    scanned_at DATETIME(6) NOT NULL,
    candidate_count INT UNSIGNED NOT NULL,
    source_health_json JSON NOT NULL,
    results_json JSON NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_cm_discovery_org_time (organization_id, scanned_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic per-organization scan reservation, including failed and concurrent attempts.
CREATE TABLE IF NOT EXISTS tn_capital_market_discovery_scan_gates (
    organization_id VARCHAR(190) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    last_attempt_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cos_feature_flags
    (flag_key,description,enabled,rollout_percentage,rollout_salt)
VALUES
    ('capital_markets.market_data.binance.enabled','Read-only Binance Spot market data source',0,0,'cm-binance-spot-off');

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261009_000139_capital_markets_cross_venue_discovery');
