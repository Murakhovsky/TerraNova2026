CREATE TABLE IF NOT EXISTS tn_real_estate_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    case_id VARCHAR(80) NOT NULL,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    property_asset_id VARCHAR(80) NOT NULL,
    inventory_id VARCHAR(80) NULL,
    subject VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'matched',
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_real_estate_case (organization_id, case_id),
    UNIQUE KEY uq_real_estate_match (organization_id, opportunity_id, property_asset_id),
    KEY ix_real_estate_case_status (organization_id, status, updated_at),
    KEY ix_real_estate_case_inventory (organization_id, inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_real_estate_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    offer_id VARCHAR(80) NOT NULL,
    case_id VARCHAR(80) NOT NULL,
    property_asset_id VARCHAR(80) NOT NULL,
    party_id VARCHAR(80) NOT NULL,
    amount_minor BIGINT NOT NULL,
    currency CHAR(3) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'proposed',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_real_estate_offer (organization_id, offer_id),
    KEY ix_real_estate_offer_case (organization_id, case_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_real_estate_operation_receipts (
    organization_id VARCHAR(64) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, operation_type, idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_real_estate_showings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    showing_id VARCHAR(80) NOT NULL,
    case_id VARCHAR(80) NOT NULL,
    property_asset_id VARCHAR(80) NOT NULL,
    client_id VARCHAR(80) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_real_estate_showing (organization_id, showing_id),
    KEY ix_real_estate_showing_case (organization_id, case_id, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260918_000062_real_estate_wave9_cutover');
