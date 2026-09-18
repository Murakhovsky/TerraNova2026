-- Symfony Sales Wave 2 isolated legacy write fixtures.
-- Extends Wave 1 with the production write-side contracts exercised by the
-- canonical Sales Application services.

ALTER TABLE tn_people
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN public_id VARCHAR(40) NULL AFTER organization_id,
    ADD COLUMN notes TEXT NULL AFTER telegram,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER notes,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_at;

UPDATE tn_people SET public_id=CONCAT('PN-', LPAD(id,5,'0')) WHERE public_id IS NULL;
ALTER TABLE tn_people ADD UNIQUE KEY uq_wave2_people_org_public_id (organization_id, public_id);

ALTER TABLE tn_leads
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN buyer_id BIGINT UNSIGNED NULL AFTER organization_id;

ALTER TABLE sales_pipelines
    ADD COLUMN code VARCHAR(100) NULL AFTER organization_id,
    ADD COLUMN initial_stage_id BIGINT UNSIGNED NULL AFTER is_default,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status;

UPDATE sales_pipelines SET
    code=CASE id WHEN 1 THEN 'default-sales' ELSE 'other-sales' END,
    initial_stage_id=CASE id WHEN 1 THEN 10 ELSE 20 END;

ALTER TABLE sales_pipeline_stages
    ADD COLUMN is_terminal TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order,
    ADD COLUMN is_lost TINYINT(1) NOT NULL DEFAULT 0 AFTER is_won,
    ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE' AFTER is_lost;

UPDATE sales_pipeline_stages SET is_terminal=1 WHERE id=11;

INSERT INTO sales_pipeline_stages
    (id,pipeline_id,organization_id,code,sort_order,name,probability_default,is_terminal,is_won,is_lost,status)
VALUES
    (12,1,'default','QUALIFIED',15,'Qualified',40,0,0,0,'ACTIVE'),
    (13,1,'default','LOST',30,'Lost',0,1,0,1,'ACTIVE'),
    (21,2,'other-org','QUALIFIED',15,'Qualified',40,0,0,0,'ACTIVE'),
    (22,2,'other-org','WON',20,'Won',100,1,1,0,'ACTIVE'),
    (23,2,'other-org','LOST',30,'Lost',0,1,0,1,'ACTIVE');

CREATE TABLE sales_pipeline_transitions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    pipeline_id BIGINT UNSIGNED NOT NULL,
    from_stage_id BIGINT UNSIGNED NOT NULL,
    to_stage_id BIGINT UNSIGNED NOT NULL,
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    conditions JSON NOT NULL
);

INSERT INTO sales_pipeline_transitions
    (id,organization_id,pipeline_id,from_stage_id,to_stage_id,requires_approval,conditions)
VALUES
    ('transition-default-new-qualified','default',1,10,12,0,JSON_ARRAY()),
    ('transition-default-qualified-won','default',1,12,11,0,JSON_ARRAY()),
    ('transition-other-new-qualified','other-org',2,20,21,0,JSON_ARRAY());

ALTER TABLE tn_client_cases
    MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ADD COLUMN type VARCHAR(32) NOT NULL DEFAULT 'buy' AFTER person_id,
    ADD COLUMN source VARCHAR(120) NULL AFTER assigned_user_id,
    ADD COLUMN inbound_request_id BIGINT UNSIGNED NULL AFTER source,
    ADD COLUMN property_type_id INT UNSIGNED NULL AFTER inbound_request_id,
    ADD COLUMN location_id INT UNSIGNED NULL AFTER property_type_id,
    ADD COLUMN budget_min DECIMAL(14,2) NULL AFTER location_id,
    ADD COLUMN area_min DECIMAL(10,2) NULL AFTER currency,
    ADD COLUMN area_max DECIMAL(10,2) NULL AFTER area_min,
    ADD COLUMN description TEXT NULL AFTER area_max,
    ADD COLUMN parameters_json JSON NULL AFTER description,
    ADD COLUMN started_at DATETIME NULL AFTER parameters_json,
    ADD COLUMN lost_reason TEXT NULL AFTER closed_at,
    ADD COLUMN lost_reason_id VARCHAR(40) NULL AFTER lost_reason,
    ADD COLUMN lost_reason_note TEXT NULL AFTER lost_reason_id,
    ADD UNIQUE KEY uq_wave2_cases_org_public_id (organization_id, public_id);

CREATE TABLE tn_property_types (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    name_uk VARCHAR(160) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE tn_locations (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    city VARCHAR(160) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE tn_client_case_request_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    client_case_id BIGINT UNSIGNED NOT NULL,
    inbound_request_id BIGINT UNSIGNED NOT NULL,
    relation_type VARCHAR(32) NOT NULL DEFAULT 'context',
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave2_request_match (organization_id,client_case_id,inbound_request_id)
);

ALTER TABLE tn_client_case_activities
    ADD COLUMN person_id BIGINT UNSIGNED NULL AFTER client_case_id,
    ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER person_id;

ALTER TABLE cos_events
    ADD COLUMN metadata JSON NULL AFTER payload,
    ADD COLUMN schema_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER metadata,
    ADD COLUMN causation_id VARCHAR(128) NULL AFTER correlation_id,
    ADD COLUMN actor_type VARCHAR(32) NULL AFTER causation_id,
    ADD COLUMN actor_id VARCHAR(100) NULL AFTER actor_type,
    ADD COLUMN recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER occurred_at;

CREATE TABLE cos_event_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(64) NOT NULL,
    organization_id VARCHAR(190) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    locked_by VARCHAR(100) NULL,
    published_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave2_outbox_event (event_id)
);

CREATE TABLE sales_operation_receipts (
    organization_id VARCHAR(190) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    mutation_id VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id,operation_type,idempotency_key)
);

CREATE TABLE cos_external_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    organization_id VARCHAR(190) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    cos_reference VARCHAR(191) NOT NULL,
    external_url VARCHAR(700) NULL,
    external_version VARCHAR(100) NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wave2_external (organization_id,provider,entity_type,external_id),
    UNIQUE KEY uq_wave2_reference (organization_id,provider,entity_type,cos_reference)
);
