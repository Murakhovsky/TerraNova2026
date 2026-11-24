-- Sales owns quoted terms, immutable revisions and operator-recorded negotiation evidence.
-- No payment, stock reservation, property transfer or accounting entry is implied.
CREATE TABLE IF NOT EXISTS sales_offers (
    organization_id VARCHAR(40) NOT NULL,
    offer_id CHAR(32) NOT NULL,
    deal_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft','presented','accepted','rejected','withdrawn','expired') NOT NULL,
    revision INT UNSIGNED NOT NULL,
    terms_version INT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    accepted_at DATETIME(6) NULL,
    PRIMARY KEY (organization_id,offer_id),
    KEY idx_sales_offer_deal (organization_id,deal_id,status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_offer_revisions (
    organization_id VARCHAR(40) NOT NULL,
    offer_id CHAR(32) NOT NULL,
    terms_version INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    currency CHAR(3) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    valid_until DATETIME NOT NULL,
    lines_json JSON NOT NULL,
    direction ENUM('sales','customer') NOT NULL,
    terms_hash CHAR(64) NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,offer_id,terms_version),
    CONSTRAINT fk_sales_offer_revision_root FOREIGN KEY (organization_id,offer_id)
        REFERENCES sales_offers (organization_id,offer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_offer_negotiations (
    organization_id VARCHAR(40) NOT NULL,
    offer_id CHAR(32) NOT NULL,
    revision INT UNSIGNED NOT NULL,
    terms_version INT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL,
    from_status VARCHAR(24) NULL,
    to_status VARCHAR(24) NOT NULL,
    evidence_reference VARCHAR(191) NULL,
    note VARCHAR(2000) NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    correlation_id VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,offer_id,revision),
    CONSTRAINT fk_sales_offer_negotiation_root FOREIGN KEY (organization_id,offer_id)
        REFERENCES sales_offers (organization_id,offer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261124_009001_sales_offer_lifecycle');
