-- E4 read-only Shopify completeness and immutable import candidate staging.
-- Legacy observations are INCOMPLETE until refreshed by a full nested pull.
ALTER TABLE pm_external_product_snapshots
 ADD COLUMN is_complete BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE pm_external_connections
 ADD COLUMN shop_currency CHAR(3) NULL,
 ADD COLUMN shop_currency_checked_at DATETIME(6) NULL;
CREATE TABLE IF NOT EXISTS pm_shopify_import_previews (
 organization_id VARCHAR(190) NOT NULL,
 preview_id CHAR(32) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 product_gid VARCHAR(191) NOT NULL,
 source_hash CHAR(64) NOT NULL,
 normalized_digest CHAR(64) NOT NULL,
 normalized_json JSON NOT NULL,
 currency CHAR(3) NOT NULL,
 source_observed_at DATETIME(6) NOT NULL,
 actor_ref VARCHAR(100) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'staged',
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,preview_id),
 UNIQUE KEY uq_pm_shopify_preview_identity (organization_id,connection_id,product_gid,source_hash),
 KEY idx_pm_shopify_preview_connection (organization_id,connection_id,state),
 CONSTRAINT fk_pm_shopify_preview_conn FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections (organization_id,connection_id),
 CONSTRAINT ck_pm_shopify_preview_state CHECK (state IN ('staged','blocked','applied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
