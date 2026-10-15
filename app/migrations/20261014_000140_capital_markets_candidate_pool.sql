-- Discovery candidates, not confirmed canonical instrument identities.
CREATE TABLE IF NOT EXISTS tn_capital_market_cross_venue_pool_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    pool_key VARCHAR(80) NOT NULL,
    seed_token VARCHAR(40) NOT NULL,
    underlying_symbol VARCHAR(40) NOT NULL,
    venue VARCHAR(32) NOT NULL,
    venue_symbol VARCHAR(80) NULL,
    quote_currency VARCHAR(16) NULL,
    listing_state VARCHAR(64) NOT NULL,
    verification_state VARCHAR(32) NOT NULL DEFAULT 'UNVERIFIED',
    economic_equivalence VARCHAR(32) NOT NULL DEFAULT 'NOT_ESTABLISHED',
    source_as_of VARCHAR(40) NOT NULL,
    observed_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_cm_candidate_identity (organization_id,pool_key,seed_token,venue),
    KEY idx_cm_candidate_venue_state (organization_id,venue,listing_state,observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261014_000140_capital_markets_candidate_pool');
