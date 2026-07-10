CREATE TABLE IF NOT EXISTS tn_migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration VARCHAR(160) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_media_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(40) NOT NULL,
    kind ENUM('image', 'video', 'document', 'model', 'other') NOT NULL DEFAULT 'image',
    storage ENUM('local', 'external') NOT NULL DEFAULT 'local',
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    extension VARCHAR(20) NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    storage_path VARCHAR(700) NOT NULL,
    public_url VARCHAR(700) NOT NULL,
    checksum CHAR(64) NULL,
    status ENUM('uploaded', 'linked', 'approved', 'rejected', 'deleted') NOT NULL DEFAULT 'uploaded',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_media_assets_public_id (public_id),
    KEY idx_tn_media_assets_kind_status (kind, status),
    KEY idx_tn_media_assets_checksum (checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_media_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    media_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(80) NOT NULL DEFAULT 'gallery',
    sort_order INT UNSIGNED NOT NULL DEFAULT 100,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_media_relations_item (media_id, entity_type, entity_id, role),
    KEY idx_tn_media_relations_entity (entity_type, entity_id, role, sort_order),
    CONSTRAINT fk_tn_media_relations_media FOREIGN KEY (media_id) REFERENCES tn_media_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260708_000004_media_core');
