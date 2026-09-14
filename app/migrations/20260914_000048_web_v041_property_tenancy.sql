ALTER TABLE tn_properties
    ADD COLUMN organization_id VARCHAR(64) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_properties_organization_catalog (organization_id, status, is_featured, updated_at);
