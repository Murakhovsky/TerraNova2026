-- Read-only, tenant-isolated candidate BBO snapshots (not authoritative market state).
CREATE TABLE IF NOT EXISTS tn_capital_market_cross_venue_quote_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    scanned_at DATETIME(6) NOT NULL,
    candidate_count INT UNSIGNED NOT NULL,
    source_health_json JSON NOT NULL,
    results_json JSON NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_cm_quote_org_time (organization_id, scanned_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_quote_scan_gates (
    organization_id VARCHAR(190) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    last_attempt_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261009_000140_capital_markets_cross_venue_quotes');
