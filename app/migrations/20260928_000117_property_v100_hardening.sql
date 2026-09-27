-- Property V0.13.0: canonical public read-side cutover.
-- The public read model is a dedicated projection. tn_properties remains compatibility-only
-- and must never be queried by canonical catalog/presentation business decisions.

CREATE TABLE IF NOT EXISTS tn_property_public_read_model LIKE tn_properties;

-- One-time compatibility bootstrap. After this migration the canonical Property projection
-- refreshes this table on every Asset/Inventory/Listing/Publication projection sync.
INSERT IGNORE INTO tn_property_public_read_model
SELECT * FROM tn_properties;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260928_000117_property_v100_hardening');
