-- Atomic tenant/source cooldown for optional bounded public REST BBO sampling.
-- A source poll attempt consumes cooldown even when provider returns an error.
CREATE TABLE IF NOT EXISTS tn_capital_market_bbo_poll_gates (
    organization_id VARCHAR(190) NOT NULL,
    source_id VARCHAR(190) NOT NULL,
    last_attempt_at DATETIME(6) NOT NULL,
    last_completed_at DATETIME(6) NULL,
    last_status VARCHAR(48) NOT NULL DEFAULT 'NOT_STARTED',
    PRIMARY KEY (organization_id,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261014_000142_capital_markets_bbo_poll_gate');
