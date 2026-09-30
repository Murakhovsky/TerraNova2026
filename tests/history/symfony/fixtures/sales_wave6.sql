-- Sales Wave 6 Communications / Integrations runtime fixture.
-- Extends earlier Symfony fixtures to the current integration control-plane contract.

ALTER TABLE cos_integrations
    ADD COLUMN integration_key VARCHAR(120) NULL AFTER organization_id,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status,
    ADD COLUMN health_status VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN' AFTER configuration_version,
    ADD COLUMN credentials_reference VARCHAR(255) NULL AFTER config,
    ADD COLUMN last_health_check_at DATETIME(6) NULL AFTER credentials_reference,
    ADD COLUMN last_success_at DATETIME(6) NULL AFTER last_health_check_at,
    ADD COLUMN last_error TEXT NULL AFTER last_success_at,
    ADD COLUMN created_by VARCHAR(191) NULL AFTER last_error,
    ADD COLUMN updated_by VARCHAR(191) NULL AFTER created_by,
    ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER updated_by,
    ADD COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) AFTER created_at;

UPDATE cos_integrations
SET integration_key=CONCAT(LOWER(capability),'.',LOWER(provider)),
    created_by='wave6-fixture',
    updated_by='wave6-fixture',
    credentials_reference=CASE
        WHEN organization_id='default' AND provider='aida' THEN 'env:WAVE6_CRM_WEBHOOK_SECRET'
        ELSE credentials_reference
    END;

ALTER TABLE cos_integrations
    ADD UNIQUE KEY uq_wave6_integrations_key (organization_id,integration_key);

CREATE TABLE sales_teams (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE'
);

INSERT INTO sales_teams(id,organization_id,code,name,status) VALUES
    ('team-default-sales','default','default-sales','Default Sales','ACTIVE');

CREATE TABLE sales_integration_routes (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    integration_id BIGINT UNSIGNED NOT NULL,
    inbound_source VARCHAR(100) NOT NULL DEFAULT 'default',
    pipeline_id VARCHAR(40) NULL,
    initial_stage_id VARCHAR(40) NULL,
    team_id VARCHAR(40) NULL,
    assignment_strategy VARCHAR(32) NOT NULL DEFAULT 'KEEP_UNASSIGNED',
    status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
    configuration_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by VARCHAR(191) NOT NULL,
    updated_by VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_wave6_integration_route_source (organization_id,integration_id,inbound_source)
);

CREATE TABLE cos_configuration_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    domain_name VARCHAR(80) NOT NULL,
    configuration_type VARCHAR(40) NOT NULL,
    entity_id VARCHAR(191) NOT NULL,
    entity_version INT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id VARCHAR(191) NOT NULL,
    reason VARCHAR(500) NULL,
    before_payload JSON NULL,
    after_payload JSON NOT NULL,
    created_at DATETIME(6) NOT NULL
);

CREATE TABLE cos_crm_inbox (
    id VARCHAR(40) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    external_event_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(160) NOT NULL,
    payload JSON NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    signature_verified TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'RECEIVED',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(100) NULL,
    processed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    correlation_id VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_wave6_crm_inbox_provider_event (organization_id,provider,external_event_id),
    KEY idx_wave6_crm_inbox_claim (status,available_at,created_at)
);

CREATE TABLE cos_sync_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    direction VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    local_version VARCHAR(100) NULL,
    external_version VARCHAR(100) NULL,
    payload_hash CHAR(64) NULL,
    cursor_value VARCHAR(500) NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    last_attempt_at DATETIME(6) NULL,
    synced_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_wave6_sync_state_entity (organization_id,provider,entity_type,external_id)
);

ALTER TABLE sales_communications
    ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER deal_id,
    ADD COLUMN external_id VARCHAR(191) NULL AFTER body,
    ADD COLUMN metadata JSON NULL AFTER external_id;

ALTER TABLE sales_communications
    ADD UNIQUE KEY uq_wave6_communication_external (organization_id,channel,external_id);
