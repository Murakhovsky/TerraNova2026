-- Order allocations are Sales fulfillment references, not stock balance or ledger.
CREATE TABLE IF NOT EXISTS sales_order_allocations (
 organization_id VARCHAR(40) NOT NULL,
 order_id CHAR(32) NOT NULL,
 allocation_id CHAR(32) NOT NULL,
 line_no SMALLINT UNSIGNED NOT NULL,
 location_id CHAR(32) NULL,
 reservation_id CHAR(32) NULL,
 stock_mode VARCHAR(16) NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 fulfilled INT UNSIGNED NOT NULL DEFAULT 0,
 cancelled INT UNSIGNED NOT NULL DEFAULT 0,
 returned INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(organization_id,order_id,allocation_id),
 UNIQUE KEY uq_sales_order_reservation(organization_id,reservation_id),
 CONSTRAINT fk_sales_order_allocation_line FOREIGN KEY(organization_id,order_id,line_no) REFERENCES sales_order_lines(organization_id,order_id,line_no),
 CONSTRAINT ck_sales_order_allocation_bounds CHECK(quantity>0 AND fulfilled+cancelled<=quantity AND returned<=fulfilled),
 CONSTRAINT ck_sales_order_allocation_mode CHECK((stock_mode='local' AND reservation_id IS NOT NULL AND location_id IS NOT NULL) OR (stock_mode='nonstock' AND reservation_id IS NULL AND location_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261125_009008_sales_order_lifecycle');
