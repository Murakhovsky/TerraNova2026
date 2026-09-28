-- Service V1.0 release closure over the proven V0.2.0 persistence schema.
UPDATE cos_module_installations
SET installed_version='1.0.0', schema_version='0.2.0', updated_at=CURRENT_TIMESTAMP
WHERE module_id='service' AND status='INSTALLED'
  AND installed_version='0.2.0' AND schema_version='0.2.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260928_000119_service_v100_release');
