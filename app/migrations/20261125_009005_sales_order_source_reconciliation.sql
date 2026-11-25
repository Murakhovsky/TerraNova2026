-- Sales owns independent reconciliation state; Product inbox only owns authenticated pointers.
CREATE TABLE IF NOT EXISTS sales_order_sources (
 organization_id VARCHAR(40) NOT NULL,
 source_id CHAR(64) NOT NULL,
 channel VARCHAR(32) NOT NULL,
 store_reference VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 external_order_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 order_id CHAR(32) NULL,
 latest_updated_at DATETIME(6) NULL,
 latest_hash CHAR(64) NULL,
 outcome VARCHAR(32) NOT NULL DEFAULT 'pending',
 blocked_reason VARCHAR(64) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,source_id),
 UNIQUE KEY uq_sales_source_identity(organization_id,channel,store_reference,external_order_id),
 CONSTRAINT fk_sales_source_order FOREIGN KEY(organization_id,order_id) REFERENCES sales_orders(organization_id,order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_source_observations (
 organization_id VARCHAR(40) NOT NULL,
 source_id CHAR(64) NOT NULL,
 provider_updated_at DATETIME(6) NOT NULL,
 provider_hash CHAR(64) NOT NULL,
 observation JSON NOT NULL,
 outcome VARCHAR(32) NOT NULL,
 blocked_reason VARCHAR(64) NULL,
 observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,source_id,provider_updated_at,provider_hash),
 CONSTRAINT fk_sales_source_observation FOREIGN KEY(organization_id,source_id) REFERENCES sales_order_sources(organization_id,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261125_009005_sales_order_source_reconciliation');
