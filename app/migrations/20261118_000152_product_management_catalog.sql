-- Product Management E1: tenant-scoped product catalog and identity.
CREATE TABLE IF NOT EXISTS pm_products (
 organization_id VARCHAR(190) NOT NULL,
 product_id CHAR(32) NOT NULL,
 product_type VARCHAR(24) NOT NULL DEFAULT 'stock',
 status VARCHAR(24) NOT NULL DEFAULT 'draft',
 name VARCHAR(255) NOT NULL,
 description TEXT NULL,
 brand VARCHAR(190) NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,product_id),
 KEY idx_pm_product_search (organization_id,status,name),
 CONSTRAINT ck_pm_product_type CHECK (product_type IN ('stock','material','service','digital')),
 CONSTRAINT ck_pm_product_status CHECK (status IN ('draft','active','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_variants (
 organization_id VARCHAR(190) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 product_id CHAR(32) NOT NULL,
 sku VARCHAR(128) NOT NULL,
 barcode VARCHAR(128) NULL,
 uom VARCHAR(16) NOT NULL DEFAULT 'pcs',
 track_inventory BOOLEAN NOT NULL DEFAULT TRUE,
 status VARCHAR(24) NOT NULL DEFAULT 'active',
 options_json JSON NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,variant_id),
 UNIQUE KEY uq_pm_variant_sku (organization_id,sku),
 KEY idx_pm_variant_product (organization_id,product_id),
 CONSTRAINT fk_pm_variant_product FOREIGN KEY (organization_id,product_id)
  REFERENCES pm_products (organization_id,product_id),
 CONSTRAINT ck_pm_variant_status CHECK (status IN ('active','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_categories (
 organization_id VARCHAR(190) NOT NULL,
 category_id CHAR(32) NOT NULL,
 parent_id CHAR(32) NULL,
 name VARCHAR(255) NOT NULL,
 slug VARCHAR(190) NOT NULL,
 PRIMARY KEY (organization_id,category_id),
 UNIQUE KEY uq_pm_category_slug (organization_id,slug),
 CONSTRAINT fk_pm_category_parent FOREIGN KEY (organization_id,parent_id)
  REFERENCES pm_categories(organization_id,category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_product_categories (
 organization_id VARCHAR(190) NOT NULL,
 product_id CHAR(32) NOT NULL,
 category_id CHAR(32) NOT NULL,
 PRIMARY KEY (organization_id,product_id,category_id),
 CONSTRAINT fk_pm_pc_product FOREIGN KEY (organization_id,product_id)
  REFERENCES pm_products(organization_id,product_id),
 CONSTRAINT fk_pm_pc_category FOREIGN KEY (organization_id,category_id)
  REFERENCES pm_categories(organization_id,category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_product_translations (
 organization_id VARCHAR(190) NOT NULL,
 product_id CHAR(32) NOT NULL,
 locale VARCHAR(16) NOT NULL,
 name VARCHAR(255) NOT NULL,
 description TEXT NULL,
 PRIMARY KEY (organization_id,product_id,locale),
 CONSTRAINT fk_pm_translation_product FOREIGN KEY (organization_id,product_id)
  REFERENCES pm_products(organization_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_media_refs (
 organization_id VARCHAR(190) NOT NULL,
 media_id CHAR(32) NOT NULL,
 product_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NULL,
 storage_ref VARCHAR(512) NOT NULL,
 role VARCHAR(32) NOT NULL DEFAULT 'gallery',
 sort_order INT NOT NULL DEFAULT 0,
 PRIMARY KEY (organization_id,media_id),
 KEY idx_pm_media_product (organization_id,product_id,sort_order),
 CONSTRAINT fk_pm_media_product FOREIGN KEY (organization_id,product_id)
  REFERENCES pm_products(organization_id,product_id),
 CONSTRAINT fk_pm_media_variant FOREIGN KEY (organization_id,variant_id)
  REFERENCES pm_variants(organization_id,variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One authoritative receipt per tenant+operation idempotency key.
CREATE TABLE IF NOT EXISTS pm_operation_receipts (
 organization_id VARCHAR(190) NOT NULL,
 idempotency_key VARCHAR(191) NOT NULL,
 operation VARCHAR(80) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 result_json JSON NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
