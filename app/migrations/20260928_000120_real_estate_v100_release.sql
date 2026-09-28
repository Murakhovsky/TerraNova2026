-- RealEstate V1.0 release closure over the proven V0.2.0 persistence schema.
UPDATE cos_module_installations
SET installed_version='1.0.0', schema_version='0.2.0', updated_at=CURRENT_TIMESTAMP
WHERE module_id='real_estate' AND status='INSTALLED'
  AND installed_version='0.2.0' AND schema_version='0.2.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260928_000120_real_estate_v100_release');
