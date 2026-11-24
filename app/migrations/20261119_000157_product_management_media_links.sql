-- Product media references are tenant-scoped metadata, never file bytes.
-- Verified file ownership and image integrity stay in the existing
-- COS Documents / Platform FileStorage boundary.
ALTER TABLE pm_media_refs
 ADD COLUMN source_file_id VARCHAR(80) NULL,
 ADD COLUMN checksum CHAR(64) NULL,
 ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'active',
 ADD CONSTRAINT ck_pm_media_status CHECK (status IN ('active','detached'));
CREATE INDEX idx_pm_media_source_file
 ON pm_media_refs (organization_id,source_file_id,status);
