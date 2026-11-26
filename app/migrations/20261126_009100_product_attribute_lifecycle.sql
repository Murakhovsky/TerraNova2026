-- Attribute definitions retain their identity even when retired.
ALTER TABLE pm_attribute_definitions ADD COLUMN lifecycle_status VARCHAR(16) NOT NULL DEFAULT 'active',
 ADD CONSTRAINT ck_pm_attr_lifecycle CHECK (lifecycle_status IN ('active','archived'));
