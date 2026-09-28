-- Sales V1.0 release closure.
-- V1 is a code/runtime contract release over the proven V0.8.6 persistence schema.
-- Advance installed_version so ActiveModuleResolver keeps existing tenants current.
UPDATE cos_module_installations
SET installed_version='1.0.0', schema_version='0.8.6', updated_at=CURRENT_TIMESTAMP
WHERE module_id='sales' AND status='INSTALLED'
  AND installed_version='0.8.6' AND schema_version='0.8.6';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260928_000118_sales_v100_release');
