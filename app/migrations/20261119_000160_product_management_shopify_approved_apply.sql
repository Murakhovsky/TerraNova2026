-- E4 canonical Shopify import is independently approved; remote Shopify stays read-only.
-- Preserve immutable staging and bind one Plan + Action per candidate.
ALTER TABLE pm_shopify_import_previews
 ADD COLUMN goal_id VARCHAR(80) NULL,
 ADD COLUMN plan_id VARCHAR(80) NULL,
 ADD COLUMN action_key VARCHAR(191) NULL,
 ADD COLUMN product_id CHAR(32) NULL,
 ADD COLUMN applied_at DATETIME(6) NULL,
 DROP CHECK ck_pm_shopify_preview_state,
 ADD CONSTRAINT ck_pm_shopify_preview_state CHECK (state IN ('staged','applying','applied','blocked'));
CREATE UNIQUE INDEX uq_pm_shopify_import_plan ON pm_shopify_import_previews (organization_id,plan_id);

-- Native, tenant scoped per-Variant recovery receipts. These do not replace
-- Product/Price writer receipts, Domain Events or canonical Action receipts.
CREATE TABLE IF NOT EXISTS pm_shopify_import_step_receipts (
 organization_id VARCHAR(190) NOT NULL,
 preview_id CHAR(32) NOT NULL,
 step_key VARCHAR(64) NOT NULL,
 external_gid VARCHAR(191) NOT NULL,
 native_id CHAR(32) NOT NULL,
 payload_digest CHAR(64) NOT NULL,
 completed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY (organization_id,preview_id,step_key),
 CONSTRAINT fk_pm_shopify_step_preview FOREIGN KEY (organization_id,preview_id)
  REFERENCES pm_shopify_import_previews (organization_id,preview_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
