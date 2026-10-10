-- An append-only first-party journal of verified Domain Action origins.
-- No automatic retroactive linking and no public write API.
CREATE TABLE IF NOT EXISTS cos_federation_outcome_origins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    domain_id VARCHAR(40) NOT NULL,
    outcome_id VARCHAR(190) NOT NULL,
    run_id VARCHAR(64) NOT NULL,
    step_id VARCHAR(64) NOT NULL,
    action_id CHAR(32) NOT NULL,
    source_fingerprint CHAR(64) NOT NULL,
    linked_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fed_origin_business (organization_id, domain_id, outcome_id),
    KEY idx_fed_origin_run (organization_id, run_id, domain_id),
    UNIQUE KEY uq_fed_origin_action_domain (organization_id, domain_id, action_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261009_000137_federation_outcome_origin_journal');
