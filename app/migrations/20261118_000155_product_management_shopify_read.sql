-- E4. Multi-store-safe Shopify observation and reconciliation schema.
-- No external writeback permissions or credentials are persisted here.
CREATE TABLE IF NOT EXISTS pm_external_connections (
 organization_id VARCHAR(190) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 provider VARCHAR(32) NOT NULL DEFAULT 'shopify',
 shop_domain VARCHAR(190) NOT NULL,
 secret_ref VARCHAR(190) NOT NULL,
 mode VARCHAR(32) NOT NULL DEFAULT 'shopify_authoritative',
 status VARCHAR(24) NOT NULL DEFAULT 'disabled',
 page_cursor VARCHAR(512) NULL,
 last_sync_at DATETIME(6) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,connection_id),
 UNIQUE KEY uq_pm_connection_shop (organization_id,provider,shop_domain),
 CONSTRAINT ck_pm_connection_mode CHECK (mode IN ('shopify_authoritative','observation_only')),
 CONSTRAINT ck_pm_connection_status CHECK (status IN ('disabled','ready','error'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_external_mappings (
 organization_id VARCHAR(190) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 entity_type VARCHAR(16) NOT NULL,
 external_gid VARCHAR(191) NOT NULL,
 canonical_id CHAR(32) NOT NULL,
 mapped_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,connection_id,entity_type,external_gid),
 UNIQUE KEY uq_pm_mapping_internal (organization_id,connection_id,entity_type,canonical_id),
 CONSTRAINT fk_pm_mapping_connection FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections(organization_id,connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_external_product_snapshots (
 organization_id VARCHAR(190) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 product_gid VARCHAR(191) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 payload_json JSON NOT NULL,
 observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,connection_id,product_gid),
 CONSTRAINT fk_pm_snapshot_connection FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections(organization_id,connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_external_stock_observations (
 organization_id VARCHAR(190) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 variant_gid VARCHAR(191) NOT NULL,
 location_gid VARCHAR(191) NOT NULL,
 inventory_gid VARCHAR(191) NOT NULL,
 on_hand DECIMAL(20,6) NULL,
 available DECIMAL(20,6) NULL,
 observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,connection_id,variant_gid,location_gid),
 CONSTRAINT fk_pm_observation_connection FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections(organization_id,connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_sync_runs (
 organization_id VARCHAR(190) NOT NULL,
 run_id CHAR(32) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'running',
 pages INT UNSIGNED NOT NULL DEFAULT 0,
 observed_products INT UNSIGNED NOT NULL DEFAULT 0,
 conflicts INT UNSIGNED NOT NULL DEFAULT 0,
 page_cursor VARCHAR(512) NULL,
 error_code VARCHAR(190) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,run_id),
 KEY idx_pm_sync_connection (organization_id,connection_id,updated_at),
 CONSTRAINT fk_pm_run_connection FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections(organization_id,connection_id),
 CONSTRAINT ck_pm_run_state CHECK (state IN ('running','completed','error','paused'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_sync_conflicts (
 organization_id VARCHAR(190) NOT NULL,
 conflict_id CHAR(32) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 external_gid VARCHAR(191) NOT NULL,
 reason VARCHAR(80) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'open',
 detail_json JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,conflict_id),
 KEY idx_pm_conflict_pending (organization_id,connection_id,state),
 UNIQUE KEY uq_pm_open_conflict_identity (organization_id,connection_id,external_gid,reason,state),
 CONSTRAINT fk_pm_conflict_connection FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections(organization_id,connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_webhook_inbox (
 organization_id VARCHAR(190) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 delivery_id VARCHAR(190) NOT NULL,
 topic VARCHAR(120) NOT NULL,
 payload_sha256 CHAR(64) NOT NULL,
 state VARCHAR(32) NOT NULL DEFAULT 'pending_reconciliation',
 received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,connection_id,delivery_id),
 KEY idx_pm_webhook_state (organization_id,connection_id,state),
 CONSTRAINT fk_pm_webhook_connection FOREIGN KEY (organization_id,connection_id)
  REFERENCES pm_external_connections(organization_id,connection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
