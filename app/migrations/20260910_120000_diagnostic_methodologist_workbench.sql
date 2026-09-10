CREATE TABLE IF NOT EXISTS diagnostic_permission_audit (
    organization_id VARCHAR(40) NOT NULL,
    audit_id CHAR(32) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    target_user_id BIGINT UNSIGNED NOT NULL,
    permission VARCHAR(100) NOT NULL,
    old_mode ENUM('inherit','allow','deny') NOT NULL,
    new_mode ENUM('inherit','allow','deny') NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (organization_id, audit_id),
    KEY idx_diagnostic_permission_audit_target (organization_id, target_user_id, created_at),
    CONSTRAINT fk_diagnostic_permission_audit_org FOREIGN KEY (organization_id) REFERENCES cos_organizations(id),
    CONSTRAINT fk_diagnostic_permission_audit_actor FOREIGN KEY (actor_user_id) REFERENCES tn_users(id),
    CONSTRAINT fk_diagnostic_permission_audit_target FOREIGN KEY (target_user_id) REFERENCES tn_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations(migration)
VALUES ('20260910_120000_diagnostic_methodologist_workbench');
