ALTER TABLE cos_rules
    ADD COLUMN domain_name VARCHAR(80) NOT NULL DEFAULT 'kernel' AFTER organization_id,
    ADD COLUMN ownership ENUM('SYSTEM', 'ADMIN') NOT NULL DEFAULT 'SYSTEM' AFTER created_by_id,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER ownership,
    ADD COLUMN system_fingerprint CHAR(64) NULL AFTER configuration_version,
    ADD COLUMN system_definition JSON NULL AFTER system_fingerprint,
    ADD COLUMN system_update_available TINYINT(1) NOT NULL DEFAULT 0 AFTER system_definition,
    ADD COLUMN admin_modified_at DATETIME(6) NULL AFTER system_update_available,
    ADD KEY idx_cos_rules_domain (organization_id, domain_name, status);

UPDATE cos_rules
SET domain_name = CASE
    WHEN LOCATE('.', code) > 0 THEN SUBSTRING_INDEX(code, '.', 1)
    ELSE 'kernel'
END;

ALTER TABLE cos_policies
    ADD COLUMN domain_name VARCHAR(80) NOT NULL DEFAULT 'kernel' AFTER organization_id,
    ADD COLUMN ownership ENUM('SYSTEM', 'ADMIN') NOT NULL DEFAULT 'SYSTEM' AFTER status,
    ADD COLUMN configuration_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER ownership,
    ADD COLUMN system_fingerprint CHAR(64) NULL AFTER configuration_version,
    ADD COLUMN system_definition JSON NULL AFTER system_fingerprint,
    ADD COLUMN system_update_available TINYINT(1) NOT NULL DEFAULT 0 AFTER system_definition,
    ADD COLUMN admin_modified_at DATETIME(6) NULL AFTER system_update_available,
    MODIFY COLUMN decision ENUM('AUTO', 'APPROVAL_REQUIRED', 'DENIED', 'HUMAN_ONLY') NOT NULL,
    ADD KEY idx_cos_policies_domain (organization_id, domain_name, status);

UPDATE cos_policies
SET domain_name = CASE
    WHEN LOCATE('.', code) > 0 THEN SUBSTRING_INDEX(code, '.', 1)
    ELSE 'kernel'
END;

CREATE TABLE IF NOT EXISTS cos_configuration_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(40) NOT NULL,
    domain_name VARCHAR(80) NOT NULL,
    configuration_type ENUM(
        'RULE',
        'POLICY',
        'PIPELINE',
        'STAGE',
        'TRANSITION',
        'LOST_REASON',
        'AGENT',
        'TEAM',
        'ASSIGNMENT',
        'INTEGRATION'
    ) NOT NULL,
    entity_id VARCHAR(191) NOT NULL,
    entity_version INT UNSIGNED NOT NULL,
    action ENUM(
        'CREATE',
        'UPDATE',
        'ENABLE',
        'DISABLE',
        'ARCHIVE',
        'ROLLBACK',
        'PROVISION'
    ) NOT NULL,
    actor_type ENUM('SYSTEM', 'USER') NOT NULL,
    actor_id VARCHAR(191) NOT NULL,
    reason VARCHAR(500) NULL,
    before_payload JSON NULL,
    after_payload JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_config_revision_entity (
        organization_id,
        domain_name,
        configuration_type,
        entity_id,
        id
    ),
    KEY idx_cos_config_revision_recent (
        organization_id,
        domain_name,
        created_at
    ),
    CONSTRAINT fk_cos_config_revision_org
        FOREIGN KEY (organization_id) REFERENCES cos_organizations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260910_000030_sales_v071_configuration_ownership');
