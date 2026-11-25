-- Independent Shopify journey observation, never a change to the placed Order or revenue.
CREATE TABLE IF NOT EXISTS sales_order_journey_evidence (
 organization_id VARCHAR(40) NOT NULL,
 order_id CHAR(32) NOT NULL,
 evidence_hash CHAR(64) NOT NULL,
 evidence_json JSON NOT NULL,
 observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,order_id,evidence_hash),
 CONSTRAINT fk_sales_order_journey FOREIGN KEY(organization_id,order_id) REFERENCES sales_orders(organization_id,order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261125_009009_sales_order_journey_evidence');
