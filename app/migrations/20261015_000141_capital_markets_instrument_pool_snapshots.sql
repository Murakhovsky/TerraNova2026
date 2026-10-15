-- Tenant-scoped append-only discovery evidence. Does not modify canonical instruments.
CREATE TABLE IF NOT EXISTS tn_capital_market_instrument_pool_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    provider VARCHAR(24) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    health VARCHAR(24) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    evidence_sha256 CHAR(64) NULL,
    provider_count INT UNSIGNED NULL,
    mapping_count INT UNSIGNED NOT NULL,
    results_json JSON NOT NULL,
    PRIMARY KEY(id),
    INDEX ix_cm_pool_snapshot_scope (organization_id,provider,venue_id,observed_at,id),
    CONSTRAINT chk_cm_pool_snapshot_health CHECK (health IN ('AVAILABLE','UNAVAILABLE')),
    CONSTRAINT fk_cm_pool_snapshot_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_capital_market_instrument_pool_scan_gates (
    organization_id VARCHAR(190) NOT NULL,
    provider VARCHAR(24) NOT NULL,
    venue_id VARCHAR(190) NOT NULL,
    last_attempt_at DATETIME(6) NOT NULL,
    PRIMARY KEY(organization_id,provider,venue_id),
    CONSTRAINT fk_cm_pool_scan_venue FOREIGN KEY (organization_id,venue_id)
        REFERENCES tn_capital_market_venues (organization_id,venue_id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261015_000141_capital_markets_instrument_pool_snapshots');
