-- Durable, one-shot provider mutation claim. No automatic replay of uncertainty.
CREATE TABLE IF NOT EXISTS pm_shopify_outbound_receipts (
 organization_id VARCHAR(190) NOT NULL,
 connection_id CHAR(32) NOT NULL,
 product_id CHAR(32) NOT NULL,
 action_key VARCHAR(191) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 state VARCHAR(16) NOT NULL,
 product_gid VARCHAR(191) NULL,
 variant_gid VARCHAR(191) NULL,
 result_json JSON NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,connection_id,product_id),
 UNIQUE KEY uq_pm_shopify_outbound_action (organization_id,action_key),
 CONSTRAINT fk_pm_outbound_connection FOREIGN KEY (organization_id,connection_id) REFERENCES pm_external_connections (organization_id,connection_id),
 CONSTRAINT ck_pm_outbound_state CHECK (state IN ('claimed','verified','uncertain'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
