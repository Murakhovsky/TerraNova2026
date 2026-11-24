-- E7 explicit quantity tiers and tombstones, preserving immutable price history.
ALTER TABLE pm_price_list_entries MODIFY COLUMN amount DECIMAL(20,4) NULL;
ALTER TABLE pm_price_list_history MODIFY COLUMN new_amount DECIMAL(20,4) NULL;
CREATE TABLE IF NOT EXISTS pm_price_list_tiers (
 organization_id VARCHAR(190) NOT NULL,
 list_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 min_quantity DECIMAL(20,6) NOT NULL,
 amount DECIMAL(20,4) NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY (organization_id,list_id,variant_id,min_quantity),
 CONSTRAINT fk_pm_plt_list FOREIGN KEY (organization_id,list_id) REFERENCES pm_price_lists (organization_id,list_id),
 CONSTRAINT fk_pm_plt_variant FOREIGN KEY (organization_id,variant_id) REFERENCES pm_variants (organization_id,variant_id),
 CONSTRAINT ck_pm_plt_qty CHECK (min_quantity>0),
 CONSTRAINT ck_pm_plt_amount CHECK (amount>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pm_price_list_tier_history (
 organization_id VARCHAR(190) NOT NULL,
 list_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 min_quantity DECIMAL(20,6) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 old_amount DECIMAL(20,4) NULL,
 new_amount DECIMAL(20,4) NULL,
 actor_ref VARCHAR(190) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,list_id,variant_id,min_quantity,revision),
 CONSTRAINT fk_pm_plth_tier FOREIGN KEY (organization_id,list_id,variant_id,min_quantity) REFERENCES pm_price_list_tiers (organization_id,list_id,variant_id,min_quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
