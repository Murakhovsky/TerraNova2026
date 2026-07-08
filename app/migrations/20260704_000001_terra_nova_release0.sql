CREATE TABLE IF NOT EXISTS tn_migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(160) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(50) NOT NULL,
    name_uk VARCHAR(120) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    country_code CHAR(2) NOT NULL DEFAULT 'UA',
    region VARCHAR(120) NULL,
    city VARCHAR(120) NOT NULL,
    district VARCHAR(120) NULL,
    slug VARCHAR(160) NOT NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_locations_slug (slug),
    KEY idx_tn_locations_city (city),
    KEY idx_tn_locations_region_city (region, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_agents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_name VARCHAR(160) NOT NULL,
    role VARCHAR(80) NOT NULL DEFAULT 'consultant',
    phone VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    telegram VARCHAR(80) NULL,
    avatar_url VARCHAR(500) NULL,
    bio TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_agents_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_properties (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    slug VARCHAR(180) NOT NULL,
    title VARCHAR(220) NOT NULL,
    deal_type ENUM('sale', 'rent', 'investment') NOT NULL DEFAULT 'sale',
    type_id INT UNSIGNED NOT NULL,
    status ENUM('draft', 'moderation', 'published', 'reserved', 'sold', 'archived') NOT NULL DEFAULT 'draft',
    source_type ENUM('own', 'partner', 'realtor', 'owner', 'developer') NOT NULL DEFAULT 'own',
    location_id INT UNSIGNED NOT NULL,
    agent_id INT UNSIGNED NULL,
    price_amount DECIMAL(14, 2) NULL,
    price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    price_period ENUM('total', 'month', 'day') NOT NULL DEFAULT 'total',
    area_total DECIMAL(10, 2) NULL,
    area_living DECIMAL(10, 2) NULL,
    land_area DECIMAL(10, 2) NULL,
    rooms DECIMAL(4, 1) NULL,
    bedrooms TINYINT UNSIGNED NULL,
    bathrooms TINYINT UNSIGNED NULL,
    floor SMALLINT UNSIGNED NULL,
    floors SMALLINT UNSIGNED NULL,
    built_year SMALLINT UNSIGNED NULL,
    address VARCHAR(255) NULL,
    latitude DECIMAL(10, 7) NULL,
    longitude DECIMAL(10, 7) NULL,
    short_description VARCHAR(500) NULL,
    description TEXT NULL,
    features_json JSON NULL,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    has_3d_tour TINYINT(1) NOT NULL DEFAULT 0,
    tour_url VARCHAR(500) NULL,
    video_url VARCHAR(500) NULL,
    meta_title VARCHAR(220) NULL,
    meta_description VARCHAR(500) NULL,
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_properties_public_id (public_id),
    UNIQUE KEY uq_tn_properties_slug (slug),
    KEY idx_tn_properties_catalog (status, deal_type, type_id, location_id, price_amount),
    KEY idx_tn_properties_featured (status, is_featured, published_at),
    KEY idx_tn_properties_agent (agent_id),
    CONSTRAINT fk_tn_properties_type FOREIGN KEY (type_id) REFERENCES tn_property_types (id),
    CONSTRAINT fk_tn_properties_location FOREIGN KEY (location_id) REFERENCES tn_locations (id),
    CONSTRAINT fk_tn_properties_agent FOREIGN KEY (agent_id) REFERENCES tn_agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(700) NOT NULL,
    alt_text VARCHAR(220) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    is_cover TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_images_item (property_id, image_url),
    KEY idx_tn_property_images_property (property_id, sort_order),
    CONSTRAINT fk_tn_property_images_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_features (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NOT NULL,
    feature_key VARCHAR(80) NOT NULL,
    feature_value VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_features_item (property_id, feature_key, feature_value),
    KEY idx_tn_property_features_key (feature_key),
    CONSTRAINT fk_tn_property_features_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_leads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(160) NOT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(160) NULL,
    role ENUM('buyer', 'seller', 'investor', 'realtor', 'developer', 'partner', 'other') NOT NULL DEFAULT 'buyer',
    deal_type ENUM('sale', 'rent', 'investment', 'consultation') NOT NULL DEFAULT 'consultation',
    message TEXT NULL,
    preferred_contact ENUM('phone', 'telegram', 'email', 'any') NOT NULL DEFAULT 'any',
    source_page VARCHAR(255) NULL,
    status ENUM('new', 'contacted', 'qualified', 'closed', 'spam') NOT NULL DEFAULT 'new',
    utm_source VARCHAR(120) NULL,
    utm_medium VARCHAR(120) NULL,
    utm_campaign VARCHAR(160) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_leads_status_created (status, created_at),
    KEY idx_tn_leads_property (property_id),
    CONSTRAINT fk_tn_leads_property FOREIGN KEY (property_id) REFERENCES tn_properties (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tn_property_types (code, name_uk, sort_order)
VALUES
    ('apartment', 'Квартира', 10),
    ('house', 'Будинок', 20),
    ('cottage', 'Котедж', 30),
    ('land', 'Земля', 40),
    ('commercial', 'Комерція', 50),
    ('new_building', 'Новобудова', 60)
ON DUPLICATE KEY UPDATE
    name_uk = VALUES(name_uk),
    sort_order = VALUES(sort_order),
    is_active = 1;

INSERT INTO tn_locations (country_code, region, city, district, slug, latitude, longitude, sort_order)
VALUES
    ('UA', 'Львівська область', 'Львів', NULL, 'lviv', 49.8396830, 24.0297170, 10),
    ('UA', 'Київська область', 'Київ', NULL, 'kyiv', 50.4501000, 30.5234000, 20),
    ('UA', 'Івано-Франківська область', 'Івано-Франківськ', NULL, 'ivano-frankivsk', 48.9226000, 24.7111000, 30),
    ('UA', 'Закарпатська область', 'Ужгород', NULL, 'uzhhorod', 48.6208000, 22.2879000, 40)
ON DUPLICATE KEY UPDATE
    region = VALUES(region),
    city = VALUES(city),
    district = VALUES(district),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    sort_order = VALUES(sort_order),
    is_active = 1;

INSERT INTO tn_agents (public_name, role, phone, email, telegram, bio)
SELECT 'Terra Nova CLUB', 'platform', NULL, 'hello@terra-nova.site', NULL, 'Перший системний консультант платформи Terra Nova CLUB.'
WHERE NOT EXISTS (
    SELECT 1 FROM tn_agents WHERE public_name = 'Terra Nova CLUB'
);

INSERT INTO tn_properties (
    public_id, slug, title, deal_type, type_id, status, source_type, location_id, agent_id,
    price_amount, price_currency, price_period, area_total, land_area, rooms, bedrooms, bathrooms, floors, built_year,
    address, latitude, longitude, short_description, description, features_json, is_featured, has_3d_tour, tour_url,
    meta_title, meta_description, published_at
)
SELECT
    'TN-0001',
    'modern-house-lviv-start',
    'Сучасний будинок біля Львова',
    'sale',
    (SELECT id FROM tn_property_types WHERE code = 'house'),
    'published',
    'own',
    (SELECT id FROM tn_locations WHERE slug = 'lviv'),
    (SELECT id FROM tn_agents WHERE public_name = 'Terra Nova CLUB' LIMIT 1),
    270000,
    'USD',
    'total',
    186.00,
    6.20,
    5.0,
    4,
    3,
    2,
    2023,
    'Львівська область',
    49.8396830,
    24.0297170,
    'Будинок для сімейного проживання з терасою, подвір''ям і готовністю до 3D-презентації.',
    'Стартовий демонстраційний об''єкт для каталогу Terra Nova CLUB. Підходить для тесту картки, фільтрів, фото й заявки.',
    JSON_OBJECT('parking', true, 'terrace', true, 'garden', true),
    1,
    1,
    '#3d-tour',
    'Сучасний будинок біля Львова | Terra Nova CLUB',
    'Демонстраційний будинок для першого запуску каталогу Terra Nova CLUB.',
    NOW()
WHERE NOT EXISTS (SELECT 1 FROM tn_properties WHERE public_id = 'TN-0001');

INSERT INTO tn_properties (
    public_id, slug, title, deal_type, type_id, status, source_type, location_id, agent_id,
    price_amount, price_currency, price_period, area_total, rooms, bedrooms, bathrooms, floor, floors, built_year,
    address, latitude, longitude, short_description, description, features_json, is_featured, has_3d_tour,
    meta_title, meta_description, published_at
)
SELECT
    'TN-0002',
    'apartment-kyiv-business',
    'Квартира для інвестицій у Києві',
    'investment',
    (SELECT id FROM tn_property_types WHERE code = 'apartment'),
    'published',
    'partner',
    (SELECT id FROM tn_locations WHERE slug = 'kyiv'),
    (SELECT id FROM tn_agents WHERE public_name = 'Terra Nova CLUB' LIMIT 1),
    145000,
    'USD',
    'total',
    74.00,
    2.0,
    1,
    1,
    11,
    24,
    2021,
    'Київ',
    50.4501000,
    30.5234000,
    'Ліквідна квартира для орендної моделі або перепродажу після пакування.',
    'Демо-об''єкт для інвестиційного сценарію Terra Nova CLUB: порівняння, ліди, презентація для інвестора.',
    JSON_OBJECT('rental_model', true, 'business_class', true),
    1,
    0,
    'Квартира для інвестицій у Києві | Terra Nova CLUB',
    'Демонстраційна інвестиційна квартира для каталогу Terra Nova CLUB.',
    NOW()
WHERE NOT EXISTS (SELECT 1 FROM tn_properties WHERE public_id = 'TN-0002');

INSERT INTO tn_properties (
    public_id, slug, title, deal_type, type_id, status, source_type, location_id, agent_id,
    price_amount, price_currency, price_period, area_total, rooms, bathrooms, floor, floors, built_year,
    address, latitude, longitude, short_description, description, features_json, is_featured, has_3d_tour,
    meta_title, meta_description, published_at
)
SELECT
    'TN-0003',
    'commercial-space-frankivsk',
    'Комерційне приміщення у центрі',
    'rent',
    (SELECT id FROM tn_property_types WHERE code = 'commercial'),
    'published',
    'realtor',
    (SELECT id FROM tn_locations WHERE slug = 'ivano-frankivsk'),
    (SELECT id FROM tn_agents WHERE public_name = 'Terra Nova CLUB' LIMIT 1),
    1800,
    'USD',
    'month',
    118.00,
    3.0,
    2,
    1,
    5,
    2018,
    'Івано-Франківськ',
    48.9226000,
    24.7111000,
    'Простір для офісу, шоуруму або сервісного бізнесу з фасадним входом.',
    'Демо-об''єкт для орендного сценарію Terra Nova CLUB і майбутніх B2B-фільтрів.',
    JSON_OBJECT('front_entrance', true, 'open_space', true),
    0,
    0,
    'Комерційне приміщення у центрі | Terra Nova CLUB',
    'Демонстраційне комерційне приміщення для каталогу Terra Nova CLUB.',
    NOW()
WHERE NOT EXISTS (SELECT 1 FROM tn_properties WHERE public_id = 'TN-0003');

INSERT IGNORE INTO tn_property_images (property_id, image_url, alt_text, sort_order, is_cover)
SELECT id, 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1400&q=80', title, 10, 1
FROM tn_properties
WHERE public_id = 'TN-0001';

INSERT IGNORE INTO tn_property_images (property_id, image_url, alt_text, sort_order, is_cover)
SELECT id, 'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1400&q=80', title, 10, 1
FROM tn_properties
WHERE public_id = 'TN-0002';

INSERT IGNORE INTO tn_property_images (property_id, image_url, alt_text, sort_order, is_cover)
SELECT id, 'https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1400&q=80', title, 10, 1
FROM tn_properties
WHERE public_id = 'TN-0003';

INSERT IGNORE INTO tn_property_features (property_id, feature_key, feature_value, sort_order)
SELECT id, 'format', '3D tour ready', 10 FROM tn_properties WHERE public_id = 'TN-0001';

INSERT IGNORE INTO tn_property_features (property_id, feature_key, feature_value, sort_order)
SELECT id, 'audience', 'investor', 10 FROM tn_properties WHERE public_id = 'TN-0002';

INSERT IGNORE INTO tn_property_features (property_id, feature_key, feature_value, sort_order)
SELECT id, 'audience', 'business', 10 FROM tn_properties WHERE public_id = 'TN-0003';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260704_000001_terra_nova_release0');
