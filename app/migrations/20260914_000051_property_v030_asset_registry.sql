-- Property V0.3.0: canonical asset registry, structural graph, typed physical specs and normalized location registry.
-- Commercial state (price, availability, listing visibility, deal state) intentionally does not belong here.

CREATE TABLE IF NOT EXISTS tn_location_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    node_id VARCHAR(80) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    node_type VARCHAR(32) NOT NULL,
    canonical_key VARCHAR(191) NOT NULL,
    name VARCHAR(160) NOT NULL,
    country_code CHAR(2) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_location_nodes_node_id (node_id),
    UNIQUE KEY uq_tn_location_nodes_canonical_key (canonical_key),
    KEY idx_tn_location_nodes_parent_type (parent_id, node_type),
    CONSTRAINT fk_tn_location_nodes_parent
        FOREIGN KEY (parent_id) REFERENCES tn_location_nodes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_addresses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    locality_node_id BIGINT UNSIGNED NOT NULL,
    street_node_id BIGINT UNSIGNED NULL,
    house_number VARCHAR(32) NULL,
    building_part VARCHAR(32) NULL,
    unit_label VARCHAR(64) NULL,
    postal_code VARCHAR(24) NULL,
    formatted_address VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_addresses_locality_street (locality_node_id, street_node_id),
    CONSTRAINT fk_tn_addresses_locality
        FOREIGN KEY (locality_node_id) REFERENCES tn_location_nodes (id),
    CONSTRAINT fk_tn_addresses_street
        FOREIGN KEY (street_node_id) REFERENCES tn_location_nodes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_geo_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_geo_points_coordinates (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_geo_boundaries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    geometry_json JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    type_code VARCHAR(50) NOT NULL,
    lifecycle VARCHAR(32) NOT NULL DEFAULT 'unknown',
    location_node_id BIGINT UNSIGNED NULL,
    address_id BIGINT UNSIGNED NULL,
    geo_point_id BIGINT UNSIGNED NULL,
    geo_boundary_id BIGINT UNSIGNED NULL,
    legacy_property_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_assets_tenant_asset (organization_id, asset_id),
    KEY idx_tn_property_assets_tenant_kind_type (organization_id, kind, type_code),
    KEY idx_tn_property_assets_location (location_node_id),
    CONSTRAINT fk_tn_property_assets_location
        FOREIGN KEY (location_node_id) REFERENCES tn_location_nodes (id),
    CONSTRAINT fk_tn_property_assets_address
        FOREIGN KEY (address_id) REFERENCES tn_addresses (id),
    CONSTRAINT fk_tn_property_assets_geo_point
        FOREIGN KEY (geo_point_id) REFERENCES tn_geo_points (id),
    CONSTRAINT fk_tn_property_assets_geo_boundary
        FOREIGN KEY (geo_boundary_id) REFERENCES tn_geo_boundaries (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_asset_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    source_asset_id VARCHAR(80) NOT NULL,
    target_asset_id VARCHAR(80) NOT NULL,
    relation_type VARCHAR(32) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    valid_from TIMESTAMP NULL,
    valid_to TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_asset_relations_edge (organization_id, source_asset_id, target_asset_id, relation_type),
    KEY idx_tn_property_asset_relations_target (organization_id, target_asset_id, relation_type),
    CONSTRAINT fk_tn_property_asset_relations_source
        FOREIGN KEY (organization_id, source_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_asset_relations_target
        FOREIGN KEY (organization_id, target_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_residential_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    total_area DECIMAL(12,3) NULL,
    living_area DECIMAL(12,3) NULL,
    rooms DECIMAL(5,2) NULL,
    bedrooms SMALLINT UNSIGNED NULL,
    bathrooms SMALLINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, asset_id),
    CONSTRAINT fk_tn_property_residential_specs_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_land_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    land_area DECIMAL(14,3) NULL,
    buildable_area DECIMAL(14,3) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, asset_id),
    CONSTRAINT fk_tn_property_land_specs_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_commercial_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    total_area DECIMAL(12,3) NULL,
    usable_area DECIMAL(12,3) NULL,
    ceiling_height DECIMAL(6,3) NULL,
    entrances SMALLINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, asset_id),
    CONSTRAINT fk_tn_property_commercial_specs_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_building_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    gross_area DECIMAL(14,3) NULL,
    floors SMALLINT UNSIGNED NULL,
    built_year SMALLINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, asset_id),
    CONSTRAINT fk_tn_property_building_specs_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000051_property_v030_asset_registry');
