CREATE TABLE IF NOT EXISTS tn_growth_signal_feeds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    feed_id VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    url_hash CHAR(64) NOT NULL,
    subject_type VARCHAR(80) NOT NULL,
    subject_id VARCHAR(191) NOT NULL,
    signal_type VARCHAR(120) NOT NULL,
    confidence DECIMAL(5,4) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_growth_signal_feed (organization_id,feed_id),
    UNIQUE KEY uq_growth_signal_feed_mapping (organization_id,url_hash,subject_type,subject_id,signal_type),
    KEY ix_growth_signal_feed_enabled (organization_id,enabled,updated_at),
    KEY ix_growth_signal_feed_subject (organization_id,subject_type,subject_id,signal_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE cos_module_installations
SET installed_version='0.26.0',
    schema_version='0.26.0',
    updated_at=CURRENT_TIMESTAMP
WHERE module_id='growth'
  AND status='INSTALLED'
  AND installed_version='0.25.0'
  AND schema_version='0.22.0';

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260923_000091_growth_v0260_rss_atom_collector');
