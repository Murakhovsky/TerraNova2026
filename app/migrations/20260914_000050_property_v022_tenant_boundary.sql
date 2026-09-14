-- Property V0.2.2: make organization ownership a database invariant.
-- Reference tables (property types, locations) intentionally remain global.

ALTER TABLE tn_properties
    ADD UNIQUE KEY uq_tn_properties_organization_id (organization_id, id);

ALTER TABLE tn_property_groups
    ADD COLUMN organization_id VARCHAR(64) NOT NULL DEFAULT 'default' AFTER id,
    ADD UNIQUE KEY uq_tn_property_groups_organization_id (organization_id, id),
    ADD KEY idx_tn_property_groups_organization_status (organization_id, status, location_id);

ALTER TABLE tn_property_submissions
    ADD COLUMN organization_id VARCHAR(64) NOT NULL DEFAULT 'default' AFTER id,
    ADD UNIQUE KEY uq_tn_property_submissions_organization_id (organization_id, id),
    ADD KEY idx_tn_property_submissions_organization_status (organization_id, status, created_at);

ALTER TABLE tn_property_images
    ADD COLUMN organization_id VARCHAR(64) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_property_images_organization_property (organization_id, property_id, sort_order);

ALTER TABLE tn_property_features
    ADD COLUMN organization_id VARCHAR(64) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_property_features_organization_property (organization_id, property_id, feature_key);

ALTER TABLE tn_property_activities
    ADD COLUMN organization_id VARCHAR(64) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_property_activities_organization_property (organization_id, property_id, created_at);

-- Backfill child ownership from the canonical parent rather than trusting defaults.
UPDATE tn_property_images i
INNER JOIN tn_properties p ON p.id = i.property_id
SET i.organization_id = p.organization_id;

UPDATE tn_property_features f
INNER JOIN tn_properties p ON p.id = f.property_id
SET f.organization_id = p.organization_id;

UPDATE tn_property_activities a
INNER JOIN tn_properties p ON p.id = a.property_id
SET a.organization_id = p.organization_id;

UPDATE tn_property_submissions s
INNER JOIN tn_properties p ON p.id = s.property_id
SET s.organization_id = p.organization_id
WHERE s.property_id IS NOT NULL;

-- A child row can only point to a PropertyAsset in the same organization.
ALTER TABLE tn_property_images
    ADD CONSTRAINT fk_tn_property_images_property_tenant
        FOREIGN KEY (organization_id, property_id)
        REFERENCES tn_properties (organization_id, id)
        ON DELETE CASCADE;

ALTER TABLE tn_property_features
    ADD CONSTRAINT fk_tn_property_features_property_tenant
        FOREIGN KEY (organization_id, property_id)
        REFERENCES tn_properties (organization_id, id)
        ON DELETE CASCADE;

ALTER TABLE tn_property_activities
    ADD CONSTRAINT fk_tn_property_activities_property_tenant
        FOREIGN KEY (organization_id, property_id)
        REFERENCES tn_properties (organization_id, id)
        ON DELETE CASCADE;

ALTER TABLE tn_property_submissions
    ADD CONSTRAINT fk_tn_property_submissions_property_tenant
        FOREIGN KEY (organization_id, property_id)
        REFERENCES tn_properties (organization_id, id);

-- Group relations are tenant-local as well. Groups are archived rather than deleted,
-- so RESTRICT is the safest behavior for an existing assignment.
ALTER TABLE tn_properties
    ADD CONSTRAINT fk_tn_properties_group_tenant
        FOREIGN KEY (organization_id, property_group_id)
        REFERENCES tn_property_groups (organization_id, id);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000050_property_v022_tenant_boundary');
