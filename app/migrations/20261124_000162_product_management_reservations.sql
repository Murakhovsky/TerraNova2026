-- E5 P1: tenant-scoped manual stock reservations. SalesOrder/OrderLine integration
-- is deliberately absent until the cross-domain order ownership ADR is accepted.
-- A reservation changes reserved only; physical on_hand changes require a movement.
CREATE TABLE IF NOT EXISTS pm_stock_reservations (
 organization_id VARCHAR(190) NOT NULL,
 reservation_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 location_id CHAR(32) NOT NULL,
 source_ref VARCHAR(191) NOT NULL,
 quantity DECIMAL(20,6) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'active',
 release_reason VARCHAR(191) NULL,
 actor_ref VARCHAR(100) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 released_at DATETIME(6) NULL,
 PRIMARY KEY (organization_id,reservation_id),
 UNIQUE KEY uq_pm_reservation_origin (organization_id,source_ref,variant_id,location_id),
 KEY idx_pm_reservation_balance (organization_id,variant_id,location_id,status),
 CONSTRAINT fk_pm_reservation_variant FOREIGN KEY (organization_id,variant_id)
  REFERENCES pm_variants(organization_id,variant_id),
 CONSTRAINT fk_pm_reservation_location FOREIGN KEY (organization_id,location_id)
  REFERENCES pm_locations(organization_id,location_id),
 CONSTRAINT ck_pm_reservation_quantity CHECK (quantity>0),
 CONSTRAINT ck_pm_reservation_state CHECK (status IN ('active','released'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
