-- COS Property: business intake staging only. Canonical technical Runs/Steps
-- remain owned exclusively by Platform Workflow.
CREATE TABLE IF NOT EXISTS cos_property_bulk_batches (
    organization_id VARCHAR(190) NOT NULL,
    batch_id VARCHAR(64) NOT NULL,
    owner_id VARCHAR(190) NOT NULL,
    digest CHAR(64) NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'staged',
    goal_id VARCHAR(64) DEFAULT NULL,
    plan_id VARCHAR(64) DEFAULT NULL,
    action_key VARCHAR(191) DEFAULT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(organization_id,batch_id),
    KEY idx_property_bulk_state(organization_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cos_property_bulk_items (
    organization_id VARCHAR(190) NOT NULL,
    batch_id VARCHAR(64) NOT NULL,
    item_index TINYINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    payload_json JSON NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'staged',
    native_asset_id VARCHAR(64) DEFAULT NULL,
    receipt_key VARCHAR(191) DEFAULT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,batch_id,item_index),
    KEY idx_property_bulk_fingerprint(organization_id,fingerprint),
    CONSTRAINT fk_property_bulk_batch FOREIGN KEY(organization_id,batch_id)
        REFERENCES cos_property_bulk_batches(organization_id,batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
