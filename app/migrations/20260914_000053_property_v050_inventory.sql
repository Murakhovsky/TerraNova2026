-- Property V0.5.0: tenant-owned commercial inventory separated from physical Property assets.
-- Property remains the canonical real-world asset. InventoryItem owns transaction type, price and commercial availability.

CREATE TABLE IF NOT EXISTS tn_property_inventory_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    transaction_type VARCHAR(24) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'available',
    price_amount DECIMAL(18,2) NULL,
    price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    price_period VARCHAR(24) NULL DEFAULT 'total',
    available_from TIMESTAMP NULL,
    available_until TIMESTAMP NULL,
    responsible_party_reference VARCHAR(191) NULL,
    source_id VARCHAR(80) NULL,
    legacy_property_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_inventory_tenant_inventory (organization_id, inventory_id),
    KEY idx_tn_property_inventory_asset (organization_id, asset_id, transaction_type),
    KEY idx_tn_property_inventory_market (organization_id, status, transaction_type, price_amount),
    KEY idx_tn_property_inventory_legacy (legacy_property_id),
    CONSTRAINT fk_tn_property_inventory_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_inventory_source
        FOREIGN KEY (organization_id, source_id)
        REFERENCES tn_property_sources (organization_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_inventory_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    reservation_id VARCHAR(80) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    reserved_for_reference VARCHAR(191) NULL,
    reserved_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    released_at TIMESTAMP NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_inventory_reservation_identity (organization_id, reservation_id),
    KEY idx_tn_property_inventory_reservation_active (organization_id, inventory_id, released_at, expires_at),
    CONSTRAINT fk_tn_property_inventory_reservation_item
        FOREIGN KEY (organization_id, inventory_id)
        REFERENCES tn_property_inventory_items (organization_id, inventory_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_inventory_price_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    price_amount DECIMAL(18,2) NULL,
    price_currency CHAR(3) NOT NULL,
    price_period VARCHAR(24) NULL,
    effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_id VARCHAR(80) NULL,
    PRIMARY KEY (id),
    KEY idx_tn_property_inventory_price_history_time (organization_id, inventory_id, effective_at),
    CONSTRAINT fk_tn_property_inventory_price_history_item
        FOREIGN KEY (organization_id, inventory_id)
        REFERENCES tn_property_inventory_items (organization_id, inventory_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_inventory_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) NULL,
    event_id VARCHAR(80) NULL,
    PRIMARY KEY (id),
    KEY idx_tn_property_inventory_status_history_time (organization_id, inventory_id, effective_at),
    CONSTRAINT fk_tn_property_inventory_status_history_item
        FOREIGN KEY (organization_id, inventory_id)
        REFERENCES tn_property_inventory_items (organization_id, inventory_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibility backfill: only legacy rows already mapped to a canonical PropertyAsset become inventory.
-- Legacy marketing/physical columns remain untouched until their dedicated compatibility bridge is retired.
INSERT IGNORE INTO tn_property_inventory_items (
    organization_id, inventory_id, asset_id, transaction_type, status,
    price_amount, price_currency, price_period, responsible_party_reference,
    legacy_property_id, created_at, updated_at
)
SELECT
    a.organization_id,
    CONCAT('INV-LEGACY-', p.id),
    a.asset_id,
    p.deal_type,
    CASE p.status
        WHEN 'published' THEN 'available'
        WHEN 'reserved' THEN 'reserved'
        WHEN 'sold' THEN 'sold'
        WHEN 'archived' THEN 'withdrawn'
        ELSE 'off_market'
    END,
    p.price_amount,
    p.price_currency,
    p.price_period,
    CASE WHEN p.agent_id IS NULL THEN NULL ELSE CONCAT('LEGACY:agent:', p.agent_id) END,
    p.id,
    p.created_at,
    p.updated_at
FROM tn_property_assets a
INNER JOIN tn_properties p ON p.id = a.legacy_property_id
WHERE a.legacy_property_id IS NOT NULL;

INSERT INTO tn_property_inventory_price_history (
    organization_id, inventory_id, price_amount, price_currency, price_period, effective_at
)
SELECT organization_id, inventory_id, price_amount, price_currency, price_period, created_at
FROM tn_property_inventory_items i
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_inventory_price_history h
    WHERE h.organization_id = i.organization_id AND h.inventory_id = i.inventory_id
);

INSERT INTO tn_property_inventory_status_history (
    organization_id, inventory_id, status, effective_at, reason
)
SELECT organization_id, inventory_id, status, created_at, 'legacy_backfill'
FROM tn_property_inventory_items i
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_inventory_status_history h
    WHERE h.organization_id = i.organization_id AND h.inventory_id = i.inventory_id
);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000053_property_v050_inventory');
