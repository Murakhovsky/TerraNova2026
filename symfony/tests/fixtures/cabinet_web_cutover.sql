-- Symfony Cabinet Web cutover fixture.
CREATE TABLE tn_property_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(64) NOT NULL DEFAULT 'default',
    submission_ref VARCHAR(40) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'new',
    source_type VARCHAR(32) NOT NULL DEFAULT 'owner',
    deal_type VARCHAR(32) NOT NULL DEFAULT 'sale',
    property_type VARCHAR(50) NOT NULL,
    title VARCHAR(220) NOT NULL,
    city VARCHAR(120) NOT NULL,
    region VARCHAR(120) NULL,
    district VARCHAR(120) NULL,
    address VARCHAR(255) NULL,
    price_amount DECIMAL(14,2) NULL,
    price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    area_total DECIMAL(10,2) NULL,
    land_area DECIMAL(10,2) NULL,
    rooms DECIMAL(4,1) NULL,
    floor SMALLINT UNSIGNED NULL,
    floors SMALLINT UNSIGNED NULL,
    built_year SMALLINT UNSIGNED NULL,
    has_3d_tour TINYINT(1) NOT NULL DEFAULT 0,
    media_links TEXT NULL,
    description TEXT NOT NULL,
    features_text TEXT NULL,
    owner_name VARCHAR(160) NOT NULL,
    owner_phone VARCHAR(50) NULL,
    owner_email VARCHAR(160) NULL,
    preferred_contact VARCHAR(32) NOT NULL DEFAULT 'any',
    source_page VARCHAR(255) NULL,
    property_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tn_property_submissions (
    id, organization_id, submission_ref, status, source_type, deal_type, property_type,
    title, city, price_amount, price_currency, area_total, rooms, description,
    owner_name, owner_phone, owner_email, preferred_contact, source_page, property_id
) VALUES (
    9301, 'default', 'CAB-9301', 'review', 'owner', 'sale', 'apartment',
    'Wave 9 Owner Submission', 'Lviv', 125000, 'USD', 64.5, 2,
    'Cabinet cutover fixture submission.',
    'CI Manager', '+380000000001', 'manager@example.test', 'email', '/cabinet', 9101
);

ALTER TABLE tn_media_assets
    ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'uploaded';

CREATE TABLE tn_media_relations (
    media_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'gallery',
    sort_order INT NOT NULL DEFAULT 100,
    PRIMARY KEY (media_id, entity_type, entity_id)
);
