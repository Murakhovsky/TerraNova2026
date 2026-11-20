-- P0 E4: fail-closed single Plan/Action claim for an external Shopify Product.
-- Multiple immutable unplanned snapshots remain legal for review, but only
-- one snapshot of (tenant,connection,Product GID) may ever be Plan-bound
-- until a separately approved manual recovery/cutover is implemented.
-- This migration deliberately fails if conflicting historical planned rows
-- already exist. Reconcile them explicitly; never drop an older receipt.
ALTER TABLE pm_shopify_import_previews
 ADD COLUMN planned_product_gid VARCHAR(191)
    GENERATED ALWAYS AS (CASE WHEN plan_id IS NULL THEN NULL ELSE product_gid END) STORED,
 ADD UNIQUE KEY uq_pm_shopify_one_planned_identity
    (organization_id,connection_id,planned_product_gid);
