-- Property V0.11.0: canonical identity review/merge workflow and pre-V1 hardening.
-- A merge resolves an incoming observation/submission into an existing canonical asset.
-- It never destructively merges two already-canonical assets.

ALTER TABLE tn_property_identity_resolutions
    ADD COLUMN review_status VARCHAR(24) NOT NULL DEFAULT 'resolved' AFTER reason,
    ADD COLUMN reviewer_reference VARCHAR(191) NULL AFTER review_status,
    ADD COLUMN review_note VARCHAR(500) NULL AFTER reviewer_reference,
    ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER resolved_at,
    ADD UNIQUE KEY uq_tn_property_identity_resolution_submission (organization_id, submission_id),
    ADD KEY idx_tn_property_identity_resolution_review (organization_id, review_status, score, created_at);

-- Canonical compatibility seed. V0.3 introduced the registry before the legacy catalogue
-- had an operational canonicalizer, while V0.5/V0.6 only projected rows already mapped
-- to tn_property_assets. Seed unmapped legacy catalogue rows once, then rebuild the
-- canonical commercial projections without making tn_properties a runtime source of truth.
INSERT IGNORE INTO tn_property_assets (
    organization_id, asset_id, kind, type_code, lifecycle, legacy_property_id, created_at, updated_at
)
SELECT
    p.organization_id,
    CONCAT('PROP-LEGACY-', p.id),
    CASE
        WHEN t.code IN ('development','residential_complex','complex') THEN 'development'
        WHEN t.code IN ('land','land_plot','plot') THEN 'land_plot'
        WHEN t.code = 'building' THEN 'building'
        WHEN t.code = 'section' THEN 'section'
        WHEN t.code = 'entrance' THEN 'entrance'
        WHEN t.code = 'floor' THEN 'floor'
        WHEN t.code IN ('house','cottage','townhouse','villa') THEN 'house'
        ELSE 'unit'
    END,
    COALESCE(NULLIF(t.code, ''), 'property'),
    'unknown',
    p.id,
    p.created_at,
    p.updated_at
FROM tn_properties p
LEFT JOIN tn_property_types t ON t.id = p.type_id
LEFT JOIN tn_property_assets a
  ON a.organization_id = p.organization_id
 AND a.legacy_property_id = p.id
WHERE a.id IS NULL;

INSERT INTO tn_property_residential_specs (
    organization_id, asset_id, total_area, living_area, rooms, bedrooms, bathrooms
)
SELECT a.organization_id, a.asset_id, p.area_total, p.area_living, p.rooms, p.bedrooms, p.bathrooms
FROM tn_property_assets a
INNER JOIN tn_properties p
  ON p.organization_id = a.organization_id
 AND p.id = a.legacy_property_id
WHERE a.kind IN ('unit','house')
ON DUPLICATE KEY UPDATE
    total_area=VALUES(total_area), living_area=VALUES(living_area), rooms=VALUES(rooms),
    bedrooms=VALUES(bedrooms), bathrooms=VALUES(bathrooms);

INSERT INTO tn_property_land_specs (organization_id, asset_id, land_area)
SELECT a.organization_id, a.asset_id, p.land_area
FROM tn_property_assets a
INNER JOIN tn_properties p
  ON p.organization_id = a.organization_id
 AND p.id = a.legacy_property_id
WHERE a.kind = 'land_plot'
ON DUPLICATE KEY UPDATE land_area=VALUES(land_area);

INSERT INTO tn_property_building_specs (organization_id, asset_id, gross_area, floors, built_year)
SELECT a.organization_id, a.asset_id, p.area_total, p.floors, p.built_year
FROM tn_property_assets a
INNER JOIN tn_properties p
  ON p.organization_id = a.organization_id
 AND p.id = a.legacy_property_id
WHERE a.kind IN ('building','development','house')
ON DUPLICATE KEY UPDATE gross_area=VALUES(gross_area), floors=VALUES(floors), built_year=VALUES(built_year);

CREATE TABLE IF NOT EXISTS tn_property_asset_legacy_links (
    organization_id VARCHAR(64) NOT NULL,
    legacy_property_id BIGINT UNSIGNED NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    link_type VARCHAR(16) NOT NULL DEFAULT 'alias',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, legacy_property_id),
    KEY idx_tn_property_asset_legacy_links_asset (organization_id, asset_id),
    CONSTRAINT fk_tn_property_asset_legacy_links_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tn_property_asset_legacy_links (organization_id, legacy_property_id, asset_id, link_type)
SELECT organization_id, legacy_property_id, asset_id, 'primary'
FROM tn_property_assets
WHERE legacy_property_id IS NOT NULL
ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id), link_type='primary';

-- Re-run compatibility projections after the V0.11 canonical seed. INSERT IGNORE and
-- history NOT EXISTS guards make this safe for installations where V0.5/V0.6 already
-- had canonical mappings.
INSERT IGNORE INTO tn_property_inventory_items (
    organization_id, inventory_id, asset_id, transaction_type, status,
    price_amount, price_currency, price_period, responsible_party_reference,
    legacy_property_id, created_at, updated_at
)
SELECT
    a.organization_id, CONCAT('INV-LEGACY-', p.id), a.asset_id, p.deal_type,
    CASE p.status
        WHEN 'published' THEN 'available'
        WHEN 'active' THEN 'available'
        WHEN 'reserved' THEN 'reserved'
        WHEN 'sold' THEN 'sold'
        WHEN 'archived' THEN 'withdrawn'
        ELSE 'off_market'
    END,
    p.price_amount, p.price_currency, p.price_period,
    CASE WHEN p.agent_id IS NULL THEN NULL ELSE CONCAT('LEGACY:agent:', p.agent_id) END,
    p.id, p.created_at, p.updated_at
FROM tn_property_assets a
INNER JOIN tn_properties p
  ON p.organization_id = a.organization_id
 AND p.id = a.legacy_property_id
WHERE a.legacy_property_id IS NOT NULL;

INSERT INTO tn_property_inventory_price_history (
    organization_id, inventory_id, price_amount, price_currency, price_period, effective_at
)
SELECT organization_id, inventory_id, price_amount, price_currency, price_period, created_at
FROM tn_property_inventory_items i
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_inventory_price_history h
    WHERE h.organization_id=i.organization_id AND h.inventory_id=i.inventory_id
);

INSERT INTO tn_property_inventory_status_history (
    organization_id, inventory_id, status, effective_at, reason
)
SELECT organization_id, inventory_id, status, created_at, 'v0.11_canonical_seed'
FROM tn_property_inventory_items i
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_inventory_status_history h
    WHERE h.organization_id=i.organization_id AND h.inventory_id=i.inventory_id
);

INSERT IGNORE INTO tn_property_listings (
    organization_id, listing_id, inventory_id, status, title, description,
    presentation_price_amount, presentation_price_currency, slug, visibility,
    seo_title, seo_description, public_features_json, legacy_property_id, created_at, updated_at
)
SELECT i.organization_id, CONCAT('LST-LEGACY-', p.id), i.inventory_id,
    CASE p.status
        WHEN 'published' THEN 'published'
        WHEN 'active' THEN 'published'
        WHEN 'archived' THEN 'archived'
        ELSE 'draft'
    END,
    p.title, COALESCE(p.description,p.short_description,''),
    p.price_amount,p.price_currency,p.slug,
    CASE WHEN p.status IN ('published','active','reserved') THEN 'public' ELSE 'private' END,
    p.meta_title,p.meta_description,p.features_json,p.id,p.created_at,p.updated_at
FROM tn_property_inventory_items i
INNER JOIN tn_properties p
  ON p.organization_id=i.organization_id
 AND p.id=i.legacy_property_id
WHERE i.legacy_property_id IS NOT NULL;

INSERT IGNORE INTO tn_property_listing_media (
    organization_id, listing_id, media_reference, sort_order, is_cover, caption
)
SELECT l.organization_id,l.listing_id,CONCAT('LEGACY:property_image:',image.id),
       image.sort_order,image.is_cover,image.alt_text
FROM tn_property_listings l
INNER JOIN tn_property_images image
  ON image.organization_id=l.organization_id
 AND image.property_id=l.legacy_property_id
WHERE l.legacy_property_id IS NOT NULL;

INSERT IGNORE INTO tn_property_publications (
    organization_id, publication_id, listing_id, channel_code, state,
    published_at, hidden_at, sync_status, created_at, updated_at
)
SELECT l.organization_id,CONCAT('PUB-LEGACY-',l.legacy_property_id),l.listing_id,'estatebook',
    CASE l.status WHEN 'published' THEN 'published' WHEN 'archived' THEN 'archived' ELSE 'draft' END,
    p.published_at,CASE WHEN p.status='archived' THEN p.updated_at ELSE NULL END,
    'legacy',p.created_at,p.updated_at
FROM tn_property_listings l
INNER JOIN tn_properties p
  ON p.organization_id=l.organization_id
 AND p.id=l.legacy_property_id
WHERE l.legacy_property_id IS NOT NULL;

INSERT INTO tn_property_listing_publication_history (organization_id, publication_id, state, effective_at)
SELECT organization_id,publication_id,state,created_at
FROM tn_property_publications publication
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_listing_publication_history history
    WHERE history.organization_id=publication.organization_id
      AND history.publication_id=publication.publication_id
);

CREATE TABLE IF NOT EXISTS tn_property_identity_review_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    resolution_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(16) NOT NULL,
    resolved_asset_id VARCHAR(80) NOT NULL,
    reviewer_reference VARCHAR(191) NULL,
    note VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_property_identity_review_audit_resolution (organization_id, resolution_id, created_at),
    KEY idx_tn_property_identity_review_audit_asset (organization_id, resolved_asset_id, created_at),
    CONSTRAINT fk_tn_property_identity_review_audit_resolution
        FOREIGN KEY (resolution_id) REFERENCES tn_property_identity_resolutions (id),
    CONSTRAINT fk_tn_property_identity_review_audit_asset
        FOREIGN KEY (organization_id, resolved_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000058_property_v0110_hardening');
