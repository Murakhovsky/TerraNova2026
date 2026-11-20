-- E3 approved CSV identity snapshot and canonical Action fencing.
-- Existing staged batches have NULL hashes and cannot be applied until re-staged
-- through the new integrity-aware preview. They remain readable for audit.
ALTER TABLE pm_import_batches
 ADD COLUMN rows_digest CHAR(64) NULL,
 ADD COLUMN action_key VARCHAR(191) NULL;
ALTER TABLE pm_import_rows
 ADD COLUMN staged_hash CHAR(64) NULL,
 ADD COLUMN staged_status VARCHAR(24) NULL,
 ADD COLUMN staged_error_code VARCHAR(128) NULL,
 ADD COLUMN receipt_key VARCHAR(191) NULL;
CREATE INDEX idx_pm_import_row_receipts ON pm_import_rows
 (organization_id,batch_id,staged_status,status);
