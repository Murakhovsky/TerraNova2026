-- A listing review is a human attestation of ONE venue symbol, not evidence of
-- issuer identity, backing, redemption rights or underlying-equity equivalence.
CREATE TABLE IF NOT EXISTS tn_capital_market_pool_listing_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    pool_key VARCHAR(80) NOT NULL,
    seed_token VARCHAR(40) NOT NULL,
    venue VARCHAR(32) NOT NULL,
    venue_symbol VARCHAR(80) NOT NULL,
    quote_currency VARCHAR(16) NOT NULL,
    source_as_of VARCHAR(40) NOT NULL,
    reviewer_actor_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(24) NOT NULL,
    evidence_url VARCHAR(512) NOT NULL,
    evidence_sha256 CHAR(64) NOT NULL,
    reviewed_at DATETIME(6) NOT NULL,
    KEY ix_cm_pool_review_scope (organization_id,pool_key,seed_token,venue,reviewed_at),
    KEY ix_cm_pool_review_actor (organization_id,reviewer_actor_id,reviewed_at),
    CONSTRAINT chk_cm_pool_review_decision CHECK (decision IN ('APPROVE_LISTING','REVOKE_LISTING'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261015_000143_capital_markets_listing_review');