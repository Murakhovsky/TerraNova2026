-- Immutable provider assignment observation; no inventory or commercial state authority.
CREATE TABLE IF NOT EXISTS sales_order_external_fulfillment_evidence (
 organization_id VARCHAR(40) NOT NULL,
 evidence_id CHAR(32) NOT NULL,
 source_id CHAR(64) NOT NULL,
 order_id CHAR(32) NOT NULL,
 request_key VARCHAR(191) NOT NULL,
 provider_updated_at DATETIME(6) NOT NULL,
 provider_hash CHAR(64) NOT NULL,
 evidence_hash CHAR(64) NOT NULL,
 evidence_json JSON NOT NULL,
 observed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,evidence_id),
 UNIQUE KEY uq_sales_fulfillment_request(organization_id,request_key),
 KEY idx_sales_fulfillment_source(organization_id,source_id,observed_at),
 CONSTRAINT fk_sales_fulfillment_source FOREIGN KEY(organization_id,source_id) REFERENCES sales_order_sources(organization_id,source_id),
 CONSTRAINT fk_sales_fulfillment_order FOREIGN KEY(organization_id,order_id) REFERENCES sales_orders(organization_id,order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261126_009011_sales_external_fulfillment_evidence');
