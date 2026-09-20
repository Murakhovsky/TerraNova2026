-- Wave 9 Property / RealEstate HTTP integration fixture.
-- Extends the Sales Wave fixtures with the canonical Property runtime schema
-- required by the Symfony business cutover. Deliberately omits production FKs:
-- the test is about runtime contracts, tenant isolation and write semantics.

ALTER TABLE tn_property_types
    MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN code VARCHAR(50) NULL AFTER id,
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 100 AFTER name_uk,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_wave9_property_type_code (code);

INSERT INTO tn_property_types(id,code,name_uk,sort_order,is_active)
VALUES(9001,'apartment','Квартира',10,1);

ALTER TABLE tn_locations
    MODIFY COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN country_code CHAR(2) NOT NULL DEFAULT 'UA' AFTER id,
    ADD COLUMN region VARCHAR(120) NULL AFTER country_code,
    ADD COLUMN district VARCHAR(120) NULL AFTER city,
    ADD COLUMN slug VARCHAR(160) NULL AFTER district,
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER slug,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 100 AFTER longitude,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_wave9_location_slug (slug);

INSERT INTO tn_locations(id,country_code,region,city,district,slug,is_active)
VALUES(9001,'UA','Lvivska','Lviv',NULL,'lviv',1);

CREATE TABLE tn_location_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    node_id VARCHAR(80) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    node_type VARCHAR(32) NOT NULL,
    canonical_key VARCHAR(191) NOT NULL,
    name VARCHAR(160) NOT NULL,
    country_code CHAR(2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_location_node_id(node_id),
    UNIQUE KEY uq_wave9_location_key(canonical_key)
);

CREATE TABLE tn_addresses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    locality_node_id BIGINT UNSIGNED NOT NULL,
    street_node_id BIGINT UNSIGNED NULL,
    house_number VARCHAR(32) NULL,
    building_part VARCHAR(32) NULL,
    unit_label VARCHAR(64) NULL,
    postal_code VARCHAR(24) NULL,
    formatted_address VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tn_geo_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_geo_point(latitude,longitude)
);

CREATE TABLE tn_property_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_property_asset(organization_id,asset_id)
);

CREATE TABLE tn_property_residential_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    total_area DECIMAL(12,3) NULL,
    living_area DECIMAL(12,3) NULL,
    rooms DECIMAL(5,2) NULL,
    floor_number SMALLINT UNSIGNED NULL,
    bedrooms SMALLINT UNSIGNED NULL,
    bathrooms SMALLINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,asset_id)
);

CREATE TABLE tn_property_land_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    land_area DECIMAL(14,3) NULL,
    buildable_area DECIMAL(14,3) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,asset_id)
);

CREATE TABLE tn_property_commercial_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    total_area DECIMAL(12,3) NULL,
    usable_area DECIMAL(12,3) NULL,
    ceiling_height DECIMAL(6,3) NULL,
    entrances SMALLINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,asset_id)
);

CREATE TABLE tn_property_building_specs (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    gross_area DECIMAL(14,3) NULL,
    floors SMALLINT UNSIGNED NULL,
    built_year SMALLINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,asset_id)
);

CREATE TABLE tn_property_asset_legacy_links (
    organization_id VARCHAR(64) NOT NULL,
    legacy_property_id BIGINT UNSIGNED NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    link_type VARCHAR(16) NOT NULL DEFAULT 'alias',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,legacy_property_id),
    KEY ix_wave9_legacy_asset(organization_id,asset_id)
);

CREATE TABLE tn_property_inventory_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    transaction_type VARCHAR(24) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'available',
    price_amount DECIMAL(18,2) NULL,
    price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    price_period VARCHAR(24) NULL DEFAULT 'total',
    available_from DATETIME NULL,
    available_until DATETIME NULL,
    responsible_party_reference VARCHAR(191) NULL,
    source_id VARCHAR(80) NULL,
    legacy_property_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_inventory(organization_id,inventory_id),
    KEY ix_wave9_inventory_asset(organization_id,asset_id)
);

CREATE TABLE tn_property_inventory_reservations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL,
    reservation_id VARCHAR(80) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    reserved_for_reference VARCHAR(191) NULL,
    reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    released_at DATETIME NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_reservation(organization_id,reservation_id),
    KEY ix_wave9_active_reservation(organization_id,inventory_id,released_at,expires_at)
);

CREATE TABLE tn_property_inventory_price_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    price_amount DECIMAL(18,2) NULL,
    price_currency CHAR(3) NOT NULL,
    price_period VARCHAR(24) NULL,
    effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_id VARCHAR(80) NULL
);

CREATE TABLE tn_property_inventory_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL,
    inventory_id VARCHAR(80) NOT NULL,
    status VARCHAR(24) NOT NULL,
    effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) NULL,
    event_id VARCHAR(80) NULL
);

CREATE TABLE tn_property_listings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_listing(organization_id,listing_id)
);

CREATE TABLE tn_property_publications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL,
    publication_id VARCHAR(80) NOT NULL,
    listing_id VARCHAR(80) NOT NULL,
    channel_code VARCHAR(64) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'draft',
    external_id VARCHAR(191) NULL,
    external_url VARCHAR(700) NULL,
    published_at DATETIME NULL,
    hidden_at DATETIME NULL,
    expires_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    sync_status VARCHAR(24) NOT NULL DEFAULT 'pending',
    sync_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_publication(organization_id,publication_id)
);

CREATE TABLE tn_property_compatibility_projection_state (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    legacy_property_id BIGINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,asset_id),
    UNIQUE KEY uq_wave9_projection_legacy(organization_id,legacy_property_id)
);

CREATE TABLE tn_agents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_name VARCHAR(160) NOT NULL,
    role VARCHAR(80) NOT NULL DEFAULT 'consultant',
    phone VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    telegram VARCHAR(80) NULL,
    avatar_url VARCHAR(500) NULL,
    bio TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE tn_property_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL DEFAULT 'default',
    title VARCHAR(220) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    group_type VARCHAR(32) NOT NULL DEFAULT 'address',
    location_id INT UNSIGNED NOT NULL,
    address VARCHAR(255) NULL,
    description TEXT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    UNIQUE KEY uq_wave9_property_group_slug (organization_id,slug)
);

CREATE TABLE tn_properties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL,
    public_id VARCHAR(40) NOT NULL,
    slug VARCHAR(191) NOT NULL,
    title VARCHAR(220) NOT NULL,
    deal_type VARCHAR(24) NOT NULL DEFAULT 'sale',
    type_id INT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    source_type VARCHAR(24) NOT NULL DEFAULT 'own',
    location_id INT UNSIGNED NOT NULL,
    property_group_id BIGINT UNSIGNED NULL,
    agent_id INT UNSIGNED NULL,
    price_amount DECIMAL(18,2) NULL,
    price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    price_period VARCHAR(24) NULL DEFAULT 'total',
    area_total DECIMAL(12,3) NULL,
    area_living DECIMAL(12,3) NULL,
    land_area DECIMAL(14,3) NULL,
    rooms DECIMAL(5,2) NULL,
    bedrooms SMALLINT UNSIGNED NULL,
    bathrooms SMALLINT UNSIGNED NULL,
    floor SMALLINT UNSIGNED NULL,
    floors SMALLINT UNSIGNED NULL,
    built_year SMALLINT UNSIGNED NULL,
    address VARCHAR(255) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    short_description VARCHAR(500) NULL,
    description TEXT NULL,
    features_json JSON NULL,
    visibility VARCHAR(24) NOT NULL DEFAULT 'private',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    has_3d_tour TINYINT(1) NOT NULL DEFAULT 0,
    tour_url VARCHAR(500) NULL,
    video_url VARCHAR(500) NULL,
    meta_title VARCHAR(220) NULL,
    meta_description VARCHAR(500) NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_legacy_public(organization_id,public_id),
    UNIQUE KEY uq_wave9_legacy_slug(organization_id,slug)
);


CREATE TABLE tn_property_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL DEFAULT 'default',
    property_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(700) NOT NULL,
    alt_text VARCHAR(220) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    is_cover TINYINT(1) NOT NULL DEFAULT 0
);

CREATE TABLE tn_property_features (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL DEFAULT 'default',
    property_id BIGINT UNSIGNED NOT NULL,
    feature_key VARCHAR(80) NOT NULL,
    feature_value VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100
);

INSERT INTO tn_agents(id,public_name,role,email)
VALUES(9101,'Wave 9 Public Agent','consultant','public-agent@example.test');

INSERT INTO tn_property_groups(id,organization_id,title,slug,group_type,location_id,address,description,status)
VALUES(9101,'default','Wave 9 Public Group','wave9-public-group','project',9001,'1 Public Street','Public-read fixture group.','active');

INSERT INTO tn_properties(
    id,organization_id,public_id,slug,title,deal_type,type_id,status,source_type,location_id,property_group_id,agent_id,
    price_amount,price_currency,price_period,area_total,rooms,bedrooms,bathrooms,floor,floors,built_year,address,
    latitude,longitude,short_description,description,visibility,is_featured,has_3d_tour,published_at
) VALUES
(
    9101,'default','PUB-9101','wave9-public-property','Wave 9 Public Property','sale',9001,'published','own',9001,9101,9101,
    125000,'USD','total',64.5,2,1,1,2,3,2026,'1 Public Street',
    49.8396830,24.0297170,'Published public fixture.','Visible public Property fixture.','public',1,0,NOW()
),
(
    9102,'default','MOD-9102','wave9-moderation-property','Wave 9 Moderation Property','sale',9001,'moderation','own',9001,9101,9101,
    99000,'USD','total',55.0,2,1,1,1,3,2026,'2 Private Street',
    49.8400000,24.0300000,'Moderation fixture.','Must never be visible through public reads.','private',0,0,NULL
),
(
    9103,'default','PRI-9103','wave9-private-published-property','Wave 9 Private Published Property','sale',9001,'published','own',9001,9101,9101,
    110000,'USD','total',58.0,2,1,1,1,3,2026,'3 Private Street',
    49.8401000,24.0301000,'Private published fixture.','Published but not public visibility.','private',0,0,NOW()
),
(
    9201,'other-org','ORG-9201','wave9-other-org-property','Wave 9 Other Org Property','sale',9001,'published','own',9001,NULL,9101,
    130000,'USD','total',70.0,3,2,1,2,4,2026,'4 Other Org Street',
    49.8402000,24.0302000,'Other organization fixture.','Public in another organization only.','public',1,0,NOW()
);

INSERT INTO tn_property_images(organization_id,property_id,image_url,alt_text,sort_order,is_cover)
VALUES('default',9101,'/img/wave9-public-property.jpg','Wave 9 Public Property',10,1);

INSERT INTO tn_property_features(organization_id,property_id,feature_key,feature_value,sort_order)
VALUES('default',9101,'fixture','public-read',10);

CREATE TABLE tn_real_estate_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave9_real_estate_case(organization_id,case_id),
    UNIQUE KEY uq_wave9_real_estate_match(organization_id,opportunity_id,property_asset_id)
);

CREATE TABLE tn_real_estate_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    UNIQUE KEY uq_wave9_real_estate_offer(organization_id,offer_id)
);

CREATE TABLE tn_real_estate_showings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    UNIQUE KEY uq_wave9_real_estate_showing(organization_id,showing_id)
);

CREATE TABLE tn_real_estate_operation_receipts (
    organization_id VARCHAR(64) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(organization_id,operation_type,idempotency_key)
);
