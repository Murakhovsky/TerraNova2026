-- Native typed attribute dictionary, category sets and managed variant identities.
CREATE TABLE IF NOT EXISTS pm_attribute_definitions (
 organization_id VARCHAR(190) NOT NULL,
 code VARCHAR(40) NOT NULL,
 name VARCHAR(190) NOT NULL,
 value_type VARCHAR(16) NOT NULL,
 unit VARCHAR(16) NULL,
 choices_json JSON NOT NULL,
 PRIMARY KEY (organization_id,code),
 CONSTRAINT ck_pm_attr_type CHECK (value_type IN ('text','decimal','boolean','enum')),
 CONSTRAINT ck_pm_attr_unit CHECK (unit IS NULL OR value_type='decimal')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pm_category_attributes (
 organization_id VARCHAR(190) NOT NULL,
 category_id CHAR(32) NOT NULL,
 code VARCHAR(40) NOT NULL,
 is_required BOOLEAN NOT NULL DEFAULT FALSE,
 is_axis BOOLEAN NOT NULL DEFAULT FALSE,
 PRIMARY KEY (organization_id,category_id,code),
 CONSTRAINT fk_pm_ca_category FOREIGN KEY (organization_id,category_id) REFERENCES pm_categories (organization_id,category_id),
 CONSTRAINT fk_pm_ca_attr FOREIGN KEY (organization_id,code) REFERENCES pm_attribute_definitions (organization_id,code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pm_product_attribute_values (
 organization_id VARCHAR(190) NOT NULL,
 product_id CHAR(32) NOT NULL,
 code VARCHAR(40) NOT NULL,
 value_json JSON NOT NULL,
 PRIMARY KEY (organization_id,product_id,code),
 CONSTRAINT fk_pm_pav_product FOREIGN KEY (organization_id,product_id) REFERENCES pm_products (organization_id,product_id),
 CONSTRAINT fk_pm_pav_attr FOREIGN KEY (organization_id,code) REFERENCES pm_attribute_definitions (organization_id,code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pm_variant_matrix_identities (
 organization_id VARCHAR(190) NOT NULL,
 product_id CHAR(32) NOT NULL,
 variant_id CHAR(32) NOT NULL,
 combination_hash CHAR(64) NOT NULL,
 PRIMARY KEY (organization_id,variant_id),
 UNIQUE KEY uq_pm_matrix_combination (organization_id,product_id,combination_hash),
 CONSTRAINT fk_pm_matrix_product FOREIGN KEY (organization_id,product_id) REFERENCES pm_products (organization_id,product_id),
 CONSTRAINT fk_pm_matrix_variant FOREIGN KEY (organization_id,variant_id) REFERENCES pm_variants (organization_id,variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
