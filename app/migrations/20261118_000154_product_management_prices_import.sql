-- Product Management E3: base price and durable CSV staging.
CREATE TABLE IF NOT EXISTS pm_base_prices (
 organization_id VARCHAR(190) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 currency CHAR(3) NOT NULL,
 amount DECIMAL(20,4) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,variant_id,currency),
 CONSTRAINT fk_pm_price_variant FOREIGN KEY (organization_id,variant_id)
  REFERENCES pm_variants(organization_id,variant_id),
 CONSTRAINT ck_pm_price_amount CHECK (amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_base_price_history (
 organization_id VARCHAR(190) NOT NULL,
 price_event_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 currency CHAR(3) NOT NULL,
 old_amount DECIMAL(20,4) NULL,
 new_amount DECIMAL(20,4) NOT NULL,
 actor_ref VARCHAR(190) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,price_event_id),
 KEY idx_pm_price_history (organization_id,variant_id,currency,created_at),
 CONSTRAINT fk_pm_price_history_variant FOREIGN KEY (organization_id,variant_id)
  REFERENCES pm_variants(organization_id,variant_id),
 CONSTRAINT ck_pm_price_history_positive CHECK (new_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_import_batches (
 organization_id VARCHAR(190) NOT NULL,
 batch_id CHAR(32) NOT NULL,
 source VARCHAR(32) NOT NULL,
 digest CHAR(64) NOT NULL,
 state VARCHAR(24) NOT NULL DEFAULT 'staged',
 actor_ref VARCHAR(190) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,batch_id),
 UNIQUE KEY uq_pm_batch_source_digest (organization_id,source,digest),
 CONSTRAINT ck_pm_import_batch_state CHECK (state IN ('staged','applying','applied','blocked'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_import_rows (
 organization_id VARCHAR(190) NOT NULL,
 batch_id CHAR(32) NOT NULL,
 row_no INT UNSIGNED NOT NULL,
 sku VARCHAR(128) NULL,
 payload_json JSON NOT NULL,
 status VARCHAR(24) NOT NULL,
 error_code VARCHAR(128) NULL,
 product_id CHAR(32) NULL,
 applied_at DATETIME(6) NULL,
 PRIMARY KEY (organization_id,batch_id,row_no),
 KEY idx_pm_import_status (organization_id,batch_id,status),
 CONSTRAINT fk_pm_import_row_batch FOREIGN KEY (organization_id,batch_id)
  REFERENCES pm_import_batches(organization_id,batch_id),
 CONSTRAINT ck_pm_import_row_status CHECK (status IN ('ready','invalid','duplicate','applied','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
