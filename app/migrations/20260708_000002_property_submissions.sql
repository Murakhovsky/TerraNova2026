CREATE TABLE IF NOT EXISTS tn_migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(160) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_ref VARCHAR(40) NOT NULL,
    status ENUM('new', 'review', 'accepted', 'rejected', 'spam') NOT NULL DEFAULT 'new',
    source_type ENUM('owner', 'realtor', 'developer', 'partner', 'other') NOT NULL DEFAULT 'owner',
    deal_type ENUM('sale', 'rent', 'investment') NOT NULL DEFAULT 'sale',
    property_type VARCHAR(50) NOT NULL,
    title VARCHAR(220) NOT NULL,
    city VARCHAR(120) NOT NULL,
    region VARCHAR(120) NULL,
    district VARCHAR(120) NULL,
    address VARCHAR(255) NULL,
    price_amount DECIMAL(14, 2) NULL,
    price_currency CHAR(3) NOT NULL DEFAULT 'USD',
    area_total DECIMAL(10, 2) NULL,
    land_area DECIMAL(10, 2) NULL,
    rooms DECIMAL(4, 1) NULL,
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
    preferred_contact ENUM('phone', 'telegram', 'email', 'any') NOT NULL DEFAULT 'any',
    source_page VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_submissions_ref (submission_ref),
    KEY idx_tn_property_submissions_status_created (status, created_at),
    KEY idx_tn_property_submissions_type_city (property_type, city),
    KEY idx_tn_property_submissions_owner_phone (owner_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260708_000002_property_submissions');
