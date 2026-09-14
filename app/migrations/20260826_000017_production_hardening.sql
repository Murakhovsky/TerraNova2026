CREATE TABLE cos_organizations (
    id VARCHAR(40) NOT NULL,
    name VARCHAR(180) NOT NULL,
    status ENUM('ACTIVE', 'SUSPENDED', 'ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    timezone VARCHAR(80) NOT NULL DEFAULT 'Europe/Kiev',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cos_organizations (id, name, status)
VALUES ('default', 'Terra Nova', 'ACTIVE');

ALTER TABLE tn_users
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_users_org_status (organization_id, status),
    ADD CONSTRAINT fk_tn_users_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

CREATE TABLE cos_organization_memberships (
    organization_id VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(80) NOT NULL,
    status ENUM('ACTIVE', 'INVITED', 'SUSPENDED', 'REVOKED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id, user_id),
    KEY idx_cos_memberships_user (user_id, status),
    CONSTRAINT fk_cos_memberships_org FOREIGN KEY (organization_id) REFERENCES cos_organizations (id),
    CONSTRAINT fk_cos_memberships_user FOREIGN KEY (user_id) REFERENCES tn_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cos_organization_memberships (organization_id, user_id, role, status)
SELECT organization_id, id, role, 'ACTIVE' FROM tn_users;

ALTER TABLE tn_people
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_people_org_email (organization_id, email),
    ADD KEY idx_tn_people_org_phone (organization_id, phone),
    ADD CONSTRAINT fk_tn_people_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

ALTER TABLE tn_client_cases
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_client_cases_org_stage (organization_id, stage, priority, updated_at),
    ADD KEY idx_tn_client_cases_org_status (organization_id, status, type, updated_at),
    ADD CONSTRAINT fk_tn_client_cases_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

ALTER TABLE tn_leads
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_leads_org_status (organization_id, status, created_at),
    ADD CONSTRAINT fk_tn_leads_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

ALTER TABLE tn_client_case_activities
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_case_activities_org_case (organization_id, client_case_id, created_at),
    ADD CONSTRAINT fk_tn_case_activities_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

ALTER TABLE tn_client_case_property_matches
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_case_property_org_case (organization_id, client_case_id, updated_at),
    ADD CONSTRAINT fk_tn_case_property_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

ALTER TABLE tn_client_case_request_matches
    ADD COLUMN organization_id VARCHAR(40) NOT NULL DEFAULT 'default' AFTER id,
    ADD KEY idx_tn_case_request_org_case (organization_id, client_case_id, created_at),
    ADD CONSTRAINT fk_tn_case_request_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id);

CREATE TABLE cos_crm_inbox (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    provider VARCHAR(80) NOT NULL,
    external_event_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(160) NOT NULL,
    payload JSON NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    signature_verified TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('RECEIVED', 'PROCESSING', 'COMPLETED', 'FAILED', 'DEAD', 'IGNORED') NOT NULL DEFAULT 'RECEIVED',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME(6) NOT NULL,
    locked_at DATETIME(6) NULL,
    locked_by VARCHAR(100) NULL,
    processed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    correlation_id VARCHAR(40) NOT NULL,
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_crm_inbox_provider_event (organization_id, provider, external_event_id),
    KEY idx_cos_crm_inbox_claim (status, available_at, created_at),
    CONSTRAINT fk_cos_crm_inbox_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cos_configuration_provisions (
    id VARCHAR(40) NOT NULL,
    organization_id VARCHAR(40) NOT NULL,
    domain_name VARCHAR(80) NOT NULL,
    manifest_hash CHAR(64) NOT NULL,
    rule_count INT UNSIGNED NOT NULL,
    policy_count INT UNSIGNED NOT NULL,
    provisioned_by VARCHAR(100) NOT NULL,
    provisioned_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_config_provisions_org_domain (organization_id, domain_name, provisioned_at),
    CONSTRAINT fk_cos_config_provisions_organization FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cos_operational_metrics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NULL,
    metric VARCHAR(160) NOT NULL,
    value DECIMAL(20, 6) NOT NULL,
    labels JSON NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_metrics_name_time (metric, recorded_at),
    KEY idx_cos_metrics_org_time (organization_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cos_agent_runs
    ADD COLUMN input_redacted TINYINT(1) NOT NULL DEFAULT 1 AFTER input_snapshot,
    ADD COLUMN input_expires_at DATETIME(6) NULL AFTER input_redacted,
    ADD KEY idx_cos_agent_runs_retention (input_expires_at);

INSERT INTO tn_migrations (migration)
VALUES ('20260826_000017_production_hardening');
