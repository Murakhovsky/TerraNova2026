-- Product Management E7: explicit tenant-owned single-currency price lists.
CREATE TABLE IF NOT EXISTS pm_price_lists (
 organization_id VARCHAR(190) NOT NULL,
 list_id CHAR(32) NOT NULL,
 code VARCHAR(64) NOT NULL,
 name VARCHAR(255) NOT NULL,
 kind VARCHAR(24) NOT NULL,
 currency CHAR(3) NOT NULL,
 PRIMARY KEY (organization_id,list_id),
 UNIQUE KEY uq_pm_price_list_code (organization_id,code),
 CONSTRAINT ck_pm_price_list_kind CHECK (kind IN ('retail','wholesale','partner'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_price_list_entries (
 organization_id VARCHAR(190) NOT NULL,
 list_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 amount DECIMAL(20,4) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY (organization_id,list_id,variant_id),
 CONSTRAINT fk_pm_ple_list FOREIGN KEY (organization_id,list_id) REFERENCES pm_price_lists (organization_id,list_id),
 CONSTRAINT fk_pm_ple_variant FOREIGN KEY (organization_id,variant_id) REFERENCES pm_variants (organization_id,variant_id),
 CONSTRAINT ck_pm_ple_amount CHECK (amount>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_price_list_history (
 organization_id VARCHAR(190) NOT NULL,
 list_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 old_amount DECIMAL(20,4) NULL,
 new_amount DECIMAL(20,4) NOT NULL,
 actor_ref VARCHAR(190) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,list_id,variant_id,revision),
 CONSTRAINT fk_pm_plh_entry FOREIGN KEY (organization_id,list_id,variant_id) REFERENCES pm_price_list_entries (organization_id,list_id,variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
