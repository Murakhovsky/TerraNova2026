-- Property V0.6.0: Listing content, channel publication and presentation media separated from Inventory and Property.

CREATE TABLE IF NOT EXISTS tn_property_channels (
    channel_code VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    channel_type VARCHAR(32) NOT NULL DEFAULT 'marketplace',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_listings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    listing_id VARCHAR(80) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    title VARCHAR(220) NOT NULL,
    description TEXT NOT NULL,
    presentation_price_amount DECIMAL(18,2) NULL,
    presentation_price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    slug VARCHAR(191) NOT NULL,
    visibility VARCHAR(24) NOT NULL DEFAULT 'public',
    seo_title VARCHAR(220) NULL,
    seo_description VARCHAR(500) NULL,
    public_features_json JSON NULL,
    legacy_property_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_listings_tenant_id (organization_id, listing_id),
    UNIQUE KEY uq_tn_property_listings_tenant_slug (organization_id, slug),
    KEY idx_tn_property_listings_inventory (organization_id, inventory_id, status),
    CONSTRAINT fk_tn_property_listings_inventory
        FOREIGN KEY (organization_id, inventory_id)
        REFERENCES tn_property_inventory_items (organization_id, inventory_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_listing_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    listing_id VARCHAR(80) NOT NULL,
    media_reference VARCHAR(191) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    is_cover TINYINT(1) NOT NULL DEFAULT 0,
    caption VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_listing_media_ref (organization_id, listing_id, media_reference),
    KEY idx_tn_property_listing_media_order (organization_id, listing_id, sort_order),
    CONSTRAINT fk_tn_property_listing_media_listing
        FOREIGN KEY (organization_id, listing_id)
        REFERENCES tn_property_listings (organization_id, listing_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_publications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    publication_id VARCHAR(80) NOT NULL,
    listing_id VARCHAR(80) NOT NULL,
    channel_code VARCHAR(64) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'draft',
    external_id VARCHAR(191) NULL,
    external_url VARCHAR(700) NULL,
    published_at TIMESTAMP NULL,
    hidden_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    last_synced_at TIMESTAMP NULL,
    sync_status VARCHAR(24) NOT NULL DEFAULT 'pending',
    sync_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_publications_identity (organization_id, publication_id),
    UNIQUE KEY uq_tn_property_publications_listing_channel (organization_id, listing_id, channel_code),
    KEY idx_tn_property_publications_state (organization_id, channel_code, state),
    CONSTRAINT fk_tn_property_publications_listing
        FOREIGN KEY (organization_id, listing_id)
        REFERENCES tn_property_listings (organization_id, listing_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_publications_channel
        FOREIGN KEY (channel_code) REFERENCES tn_property_channels (channel_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_listing_publication_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    publication_id VARCHAR(80) NOT NULL,
    state VARCHAR(24) NOT NULL,
    effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    external_id VARCHAR(191) NULL,
    external_url VARCHAR(700) NULL,
    event_id VARCHAR(80) NULL,
    PRIMARY KEY (id),
    KEY idx_tn_property_listing_publication_history_time (organization_id, publication_id, effective_at),
    CONSTRAINT fk_tn_property_listing_publication_history_publication
        FOREIGN KEY (organization_id, publication_id)
        REFERENCES tn_property_publications (organization_id, publication_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tn_property_channels (channel_code, name, channel_type)
VALUES ('estatebook', 'EstateBook', 'owned'), ('olx', 'OLX', 'marketplace'), ('dimria', 'DIM.RIA', 'marketplace'), ('partner_mls', 'Partner MLS', 'mls'), ('private', 'Private presentation', 'private')
ON DUPLICATE KEY UPDATE name = VALUES(name), channel_type = VALUES(channel_type), is_active = 1;

-- Compatibility projection from the legacy public catalog into the new Listing layer.
INSERT IGNORE INTO tn_property_listings (
    organization_id, listing_id, inventory_id, status, title, description,
    presentation_price_amount, presentation_price_currency, slug, visibility,
    seo_title, seo_description, public_features_json, legacy_property_id, created_at, updated_at
)
SELECT i.organization_id, CONCAT('LST-LEGACY-', p.id), i.inventory_id,
    CASE p.status WHEN 'published' THEN 'published' WHEN 'archived' THEN 'archived' ELSE 'draft' END,
    p.title, COALESCE(p.description, p.short_description, ''),
    p.price_amount, p.price_currency, p.slug,
    CASE WHEN p.status IN ('published','reserved') THEN 'public' ELSE 'private' END,
    p.meta_title, p.meta_description, p.features_json, p.id, p.created_at, p.updated_at
FROM tn_property_inventory_items i
INNER JOIN tn_properties p ON p.id = i.legacy_property_id
WHERE i.legacy_property_id IS NOT NULL;

INSERT IGNORE INTO tn_property_listing_media (organization_id, listing_id, media_reference, sort_order, is_cover, caption)
SELECT l.organization_id, l.listing_id, CONCAT('LEGACY:property_image:', image.id), image.sort_order, image.is_cover, image.alt_text
FROM tn_property_listings l
INNER JOIN tn_property_images image ON image.property_id = l.legacy_property_id
WHERE l.legacy_property_id IS NOT NULL;

INSERT IGNORE INTO tn_property_publications (
    organization_id, publication_id, listing_id, channel_code, state, published_at, hidden_at, sync_status, created_at, updated_at
)
SELECT l.organization_id, CONCAT('PUB-LEGACY-', l.legacy_property_id), l.listing_id, 'estatebook',
    CASE l.status WHEN 'published' THEN 'published' WHEN 'archived' THEN 'archived' ELSE 'draft' END,
    p.published_at, CASE WHEN p.status = 'archived' THEN p.updated_at ELSE NULL END, 'legacy', p.created_at, p.updated_at
FROM tn_property_listings l
INNER JOIN tn_properties p ON p.id = l.legacy_property_id
WHERE l.legacy_property_id IS NOT NULL;

INSERT INTO tn_property_listing_publication_history (organization_id, publication_id, state, effective_at)
SELECT organization_id, publication_id, state, created_at
FROM tn_property_publications publication
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_listing_publication_history history
    WHERE history.organization_id = publication.organization_id AND history.publication_id = publication.publication_id
);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000054_property_v060_listings_publication');
