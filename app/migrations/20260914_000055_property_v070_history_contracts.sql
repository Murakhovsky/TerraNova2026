-- Property V0.7.0: append-only historical projections for physical Property state and relation graph.
-- Kernel domain events remain the canonical cross-domain event stream; these tables are query projections.

CREATE TABLE IF NOT EXISTS tn_property_lifecycle_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    lifecycle VARCHAR(32) NOT NULL,
    effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason VARCHAR(255) NULL,
    event_id VARCHAR(80) NULL,
    PRIMARY KEY (id),
    KEY idx_tn_property_lifecycle_history_time (organization_id, asset_id, effective_at),
    CONSTRAINT fk_tn_property_lifecycle_history_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_relation_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    source_asset_id VARCHAR(80) NOT NULL,
    target_asset_id VARCHAR(80) NOT NULL,
    relation_type VARCHAR(32) NOT NULL,
    operation VARCHAR(16) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    effective_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_id VARCHAR(80) NULL,
    PRIMARY KEY (id),
    KEY idx_tn_property_relation_history_source (organization_id, source_asset_id, effective_at),
    KEY idx_tn_property_relation_history_target (organization_id, target_asset_id, effective_at),
    CONSTRAINT fk_tn_property_relation_history_source
        FOREIGN KEY (organization_id, source_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_relation_history_target
        FOREIGN KEY (organization_id, target_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tn_property_lifecycle_history (organization_id, asset_id, lifecycle, effective_at, reason)
SELECT organization_id, asset_id, lifecycle, created_at, 'v0.7_baseline'
FROM tn_property_assets asset
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_lifecycle_history history
    WHERE history.organization_id=asset.organization_id AND history.asset_id=asset.asset_id
);

INSERT INTO tn_property_relation_history (
    organization_id, source_asset_id, target_asset_id, relation_type, operation, sort_order, effective_at
)
SELECT organization_id, source_asset_id, target_asset_id, relation_type, 'created', sort_order, created_at
FROM tn_property_asset_relations relation_row
WHERE NOT EXISTS (
    SELECT 1 FROM tn_property_relation_history history
    WHERE history.organization_id=relation_row.organization_id
      AND history.source_asset_id=relation_row.source_asset_id
      AND history.target_asset_id=relation_row.target_asset_id
      AND history.relation_type=relation_row.relation_type
);

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000055_property_v070_history_contracts');
