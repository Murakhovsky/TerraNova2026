-- Extend the existing E5 reservation ledger, never create a second stock balance.
CREATE TABLE IF NOT EXISTS pm_order_reservation_operations (
 organization_id VARCHAR(190) NOT NULL,
 operation_id CHAR(32) NOT NULL,
 reservation_id CHAR(32) NOT NULL,
 operation_type VARCHAR(16) NOT NULL,
 quantity DECIMAL(20,6) NOT NULL,
 movement_id CHAR(32) NULL,
 source_ref VARCHAR(191) NOT NULL,
 actor_ref VARCHAR(190) NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,operation_id),
 KEY idx_pm_order_reservation_operation(organization_id,reservation_id,operation_type),
 CONSTRAINT fk_pm_order_reservation_operation FOREIGN KEY(organization_id,reservation_id) REFERENCES pm_stock_reservations(organization_id,reservation_id),
 CONSTRAINT ck_pm_order_reservation_operation CHECK(operation_type IN ('reserve','commit','release','return') AND quantity>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration) VALUES ('20261125_009007_product_order_reservation_evidence');
