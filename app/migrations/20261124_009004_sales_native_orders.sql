-- Native commerce orders: no payment or inventory write is implied by placement.
CREATE TABLE IF NOT EXISTS sales_orders (
 organization_id VARCHAR(40) NOT NULL,
 order_id CHAR(32) NOT NULL,
 owner_user_id BIGINT UNSIGNED NOT NULL,
 deal_id BIGINT UNSIGNED NULL,
 customer_id BIGINT UNSIGNED NULL,
 channel VARCHAR(32) NOT NULL,
 store_reference VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 external_order_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
 status ENUM('placed','confirmed','cancelled','completed') NOT NULL DEFAULT 'placed',
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 currency CHAR(3) NOT NULL,
 subtotal DECIMAL(14,2) NOT NULL,
 discount DECIMAL(14,2) NOT NULL,
 tax DECIMAL(14,2) NOT NULL,
 shipping DECIMAL(14,2) NOT NULL,
 total DECIMAL(14,2) NOT NULL,
 attribution_json JSON NOT NULL,
 placed_at DATETIME(6) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(organization_id,order_id),
 UNIQUE KEY uq_sales_order_source(organization_id,channel,store_reference,external_order_id),
 KEY idx_sales_order_owner(organization_id,owner_user_id,placed_at),
 KEY idx_sales_order_deal(organization_id,deal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sales_order_lines (
 organization_id VARCHAR(40) NOT NULL,
 order_id CHAR(32) NOT NULL,
 line_no SMALLINT UNSIGNED NOT NULL,
 sku VARCHAR(120) NOT NULL,
 title VARCHAR(180) NOT NULL,
 product_id VARCHAR(80) NULL,
 variant_id VARCHAR(80) NULL,
 quantity INT UNSIGNED NOT NULL,
 unit_price DECIMAL(14,2) NOT NULL,
 subtotal DECIMAL(14,2) NOT NULL,
 PRIMARY KEY(organization_id,order_id,line_no),
 CONSTRAINT fk_sales_order_line FOREIGN KEY(organization_id,order_id) REFERENCES sales_orders(organization_id,order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable versioned evidence; consumers must deduplicate by event_id.
CREATE TABLE IF NOT EXISTS sales_order_evidence (
 organization_id VARCHAR(40) NOT NULL,
 event_id CHAR(32) NOT NULL,
 order_id CHAR(32) NOT NULL,
 revision INT UNSIGNED NOT NULL,
 event_type VARCHAR(64) NOT NULL,
 occurred_at DATETIME(6) NOT NULL,
 payload JSON NOT NULL,
 PRIMARY KEY(organization_id,event_id),
 UNIQUE KEY uq_sales_order_evidence_revision(organization_id,order_id,revision,event_type),
 CONSTRAINT fk_sales_order_evidence FOREIGN KEY(organization_id,order_id) REFERENCES sales_orders(organization_id,order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261124_009004_sales_native_orders');
