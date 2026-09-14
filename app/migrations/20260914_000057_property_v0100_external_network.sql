-- Property V0.10.0: transport-agnostic External Property Network.
-- Network records are synchronization evidence, not canonical Property truth.
-- Incoming records enter through PropertySubmission; remote DELETE is a tombstone and never deletes a canonical asset.
-- Secrets are resolved outside Property through configuration_reference; credentials are never persisted here.

ALTER TABLE tn_property_submissions
    MODIFY source_type ENUM('owner','realtor','developer','partner','other','network') NOT NULL DEFAULT 'owner',
    MODIFY owner_name VARCHAR(160) NULL;

CREATE TABLE IF NOT EXISTS tn_property_network_connectors (
    organization_id VARCHAR(64) NOT NULL,
    connector_id VARCHAR(80) NOT NULL,
    source_id VARCHAR(80) NOT NULL,
    adapter_code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    connector_type VARCHAR(32) NOT NULL,
    direction VARCHAR(16) NOT NULL DEFAULT 'IMPORT',
    status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
    configuration_reference VARCHAR(191) NULL,
    import_cursor VARCHAR(500) NULL,
    export_cursor VARCHAR(500) NULL,
    last_import_at TIMESTAMP NULL,
    last_export_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, connector_id),
    KEY idx_tn_property_network_connector_source (organization_id, source_id),
    KEY idx_tn_property_network_connector_runtime (organization_id, status, direction, adapter_code),
    CONSTRAINT fk_tn_property_network_connector_source
        FOREIGN KEY (organization_id, source_id)
        REFERENCES tn_property_sources (organization_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_network_sync_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    sync_run_id VARCHAR(80) NOT NULL,
    connector_id VARCHAR(80) NOT NULL,
    direction VARCHAR(16) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'RUNNING',
    cursor_before VARCHAR(500) NULL,
    cursor_after VARCHAR(500) NULL,
    records_received INT UNSIGNED NOT NULL DEFAULT 0,
    records_imported INT UNSIGNED NOT NULL DEFAULT 0,
    records_exported INT UNSIGNED NOT NULL DEFAULT 0,
    records_skipped INT UNSIGNED NOT NULL DEFAULT 0,
    records_failed INT UNSIGNED NOT NULL DEFAULT 0,
    tombstones INT UNSIGNED NOT NULL DEFAULT 0,
    correlation_id VARCHAR(80) NULL,
    error_summary VARCHAR(1000) NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_network_run_identity (organization_id, sync_run_id),
    KEY idx_tn_property_network_run_connector (organization_id, connector_id, started_at),
    KEY idx_tn_property_network_run_status (organization_id, status, started_at),
    CONSTRAINT fk_tn_property_network_run_connector
        FOREIGN KEY (organization_id, connector_id)
        REFERENCES tn_property_network_connectors (organization_id, connector_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_network_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    network_record_id VARCHAR(80) NOT NULL,
    connector_id VARCHAR(80) NOT NULL,
    source_id VARCHAR(80) NOT NULL,
    sync_run_id VARCHAR(80) NOT NULL,
    direction VARCHAR(16) NOT NULL,
    external_entity_type VARCHAR(80) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    external_version VARCHAR(191) NULL,
    operation VARCHAR(16) NOT NULL DEFAULT 'UPSERT',
    payload_json JSON NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'RECEIVED',
    submission_id BIGINT UNSIGNED NULL,
    asset_id VARCHAR(80) NULL,
    error_message VARCHAR(1000) NULL,
    observed_at TIMESTAMP NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_network_record_identity (organization_id, network_record_id),
    UNIQUE KEY uq_tn_property_network_record_delivery (
        organization_id, connector_id, direction, external_entity_type, external_id, payload_hash
    ),
    KEY idx_tn_property_network_record_external (organization_id, source_id, external_entity_type, external_id),
    KEY idx_tn_property_network_record_run (organization_id, sync_run_id),
    KEY idx_tn_property_network_record_status (organization_id, status, received_at),
    KEY idx_tn_property_network_record_submission (organization_id, submission_id),
    KEY idx_tn_property_network_record_asset (organization_id, asset_id),
    CONSTRAINT fk_tn_property_network_record_connector
        FOREIGN KEY (organization_id, connector_id)
        REFERENCES tn_property_network_connectors (organization_id, connector_id),
    CONSTRAINT fk_tn_property_network_record_source
        FOREIGN KEY (organization_id, source_id)
        REFERENCES tn_property_sources (organization_id, source_id),
    CONSTRAINT fk_tn_property_network_record_run
        FOREIGN KEY (organization_id, sync_run_id)
        REFERENCES tn_property_network_sync_runs (organization_id, sync_run_id),
    CONSTRAINT fk_tn_property_network_record_submission
        FOREIGN KEY (organization_id, submission_id)
        REFERENCES tn_property_submissions (organization_id, id),
    CONSTRAINT fk_tn_property_network_record_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000057_property_v0100_external_network');
