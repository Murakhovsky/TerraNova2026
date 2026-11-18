-- Product Management E2: exact decimal ledger and balance projection.
CREATE TABLE IF NOT EXISTS pm_locations (
 organization_id VARCHAR(190) NOT NULL,
 location_id CHAR(32) NOT NULL,
 code VARCHAR(64) NOT NULL,
 name VARCHAR(255) NOT NULL,
 kind VARCHAR(24) NOT NULL DEFAULT 'warehouse',
 status VARCHAR(24) NOT NULL DEFAULT 'active',
 PRIMARY KEY (organization_id,location_id),
 UNIQUE KEY uq_pm_location_code (organization_id,code),
 CONSTRAINT ck_pm_location_status CHECK (status IN ('active','inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_stock_balances (
 organization_id VARCHAR(190) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 location_id CHAR(32) NOT NULL,
 on_hand DECIMAL(20,6) NOT NULL DEFAULT 0,
 reserved DECIMAL(20,6) NOT NULL DEFAULT 0,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,variant_id,location_id),
 CONSTRAINT fk_pm_balance_variant FOREIGN KEY (organization_id,variant_id)
  REFERENCES pm_variants(organization_id,variant_id),
 CONSTRAINT fk_pm_balance_location FOREIGN KEY (organization_id,location_id)
  REFERENCES pm_locations(organization_id,location_id),
 CONSTRAINT ck_pm_balance_nonnegative CHECK (on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_stock_movements (
 organization_id VARCHAR(190) NOT NULL,
 movement_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 location_id CHAR(32) NOT NULL,
 quantity_delta DECIMAL(20,6) NOT NULL,
 movement_type VARCHAR(24) NOT NULL,
 source_ref VARCHAR(191) NOT NULL,
 idempotency_key VARCHAR(191) NOT NULL,
 actor_ref VARCHAR(190) NOT NULL,
 occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,movement_id),
 UNIQUE KEY uq_pm_movement_idem (organization_id,idempotency_key),
 KEY idx_pm_movement_stock (organization_id,variant_id,location_id,occurred_at),
 CONSTRAINT fk_pm_movement_variant FOREIGN KEY (organization_id,variant_id)
  REFERENCES pm_variants(organization_id,variant_id),
 CONSTRAINT fk_pm_movement_location FOREIGN KEY (organization_id,location_id)
  REFERENCES pm_locations(organization_id,location_id),
 CONSTRAINT ck_pm_movement_qty CHECK (quantity_delta <> 0),
 CONSTRAINT ck_pm_movement_type CHECK (movement_type IN ('receipt','issue','adjustment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- No UPDATE/DELETE API on posted stock movements. Service guards row writes.
