-- Property V0.12.0 — Canonical Runtime Cutover.
-- Canonical Asset / Inventory / Listing / Publication tables are authoritative.
-- tn_properties remains a one-way compatibility projection for legacy read surfaces.

ALTER TABLE tn_property_residential_specs
    ADD COLUMN floor_number SMALLINT UNSIGNED NULL AFTER rooms;

CREATE TABLE IF NOT EXISTS tn_property_compatibility_projection_state (
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    legacy_property_id BIGINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, asset_id),
    UNIQUE KEY uq_tn_property_compat_projection_legacy (organization_id, legacy_property_id),
    KEY idx_tn_property_compat_projection_synced (organization_id, synced_at),
    CONSTRAINT fk_tn_property_compat_projection_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Establish a baseline projection fingerprint for already linked assets.
-- Runtime sync will replace this marker with the full state fingerprint on the next mutation.
INSERT INTO tn_property_compatibility_projection_state (
    organization_id, asset_id, legacy_property_id, fingerprint, synced_at
)
SELECT
    a.organization_id,
    a.asset_id,
    a.legacy_property_id,
    SHA2(CONCAT('v0.12-baseline:', a.organization_id, ':', a.asset_id, ':', a.legacy_property_id), 256),
    NOW()
FROM tn_property_assets a
WHERE a.legacy_property_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    legacy_property_id = VALUES(legacy_property_id),
    synced_at = VALUES(synced_at);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260915_000059_property_v0120_runtime_cutover');
