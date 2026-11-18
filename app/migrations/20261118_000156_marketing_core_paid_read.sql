-- MKT M0/M1. Marketing-owned state only, no paid mutation, no Finance Expense.
CREATE TABLE IF NOT EXISTS mkt_campaigns (
 organization_id VARCHAR(190) NOT NULL,
 campaign_id CHAR(32) NOT NULL,
 name VARCHAR(190) NOT NULL,
 objective VARCHAR(64) NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'DRAFT',
 brief_json JSON NOT NULL,
 audience_json JSON NOT NULL,
 creative_json JSON NOT NULL,
 planned_budget_minor BIGINT UNSIGNED NULL,
 currency CHAR(3) NULL,
 revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,campaign_id),
 KEY ix_mkt_status(organization_id,status,updated_at),
 CONSTRAINT ck_mkt_status CHECK(status IN ('DRAFT','READY_FOR_REVIEW','APPROVED','PAUSED','COMPLETED','ARCHIVED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_campaign_revisions (
 organization_id VARCHAR(190) NOT NULL,
 campaign_id CHAR(32) NOT NULL,
 revision BIGINT UNSIGNED NOT NULL,
 snapshot_json JSON NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,campaign_id,revision),
 CONSTRAINT fk_mkt_revision FOREIGN KEY(organization_id,campaign_id)
 REFERENCES mkt_campaigns(organization_id,campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_operation_receipts (
 organization_id VARCHAR(190) NOT NULL,
 idempotency_key VARCHAR(191) NOT NULL,
 operation VARCHAR(80) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 result_json JSON NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_event_outbox (
 organization_id VARCHAR(190) NOT NULL,
 event_id CHAR(32) NOT NULL,
 event_type VARCHAR(128) NOT NULL,
 aggregate_ref VARCHAR(80) NOT NULL,
 payload_json JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 published_at DATETIME(6) NULL,
 PRIMARY KEY(organization_id,event_id),
 KEY ix_mkt_unpublished(published_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_paid_accounts (
 organization_id VARCHAR(190) NOT NULL,
 account_id CHAR(32) NOT NULL,
 provider VARCHAR(32) NOT NULL,
 external_account_id VARCHAR(80) NOT NULL,
 credential_ref VARCHAR(190) NOT NULL,
 state VARCHAR(32) NOT NULL DEFAULT 'READ_ONLY',
 timezone VARCHAR(64) NOT NULL,
 currency CHAR(3) NOT NULL,
 last_verified_at DATETIME(6) NULL,
 last_sync_at DATETIME(6) NULL,
 last_error_code VARCHAR(80) NULL,
 PRIMARY KEY(organization_id,account_id),
 UNIQUE KEY uq_mkt_external(organization_id,provider,external_account_id),
 CONSTRAINT ck_mkt_account_state CHECK(state IN ('READ_ONLY','DEGRADED','DISCONNECTED','COS_MANAGED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_paid_objects (
 organization_id VARCHAR(190) NOT NULL,
 account_id CHAR(32) NOT NULL,
 object_type VARCHAR(16) NOT NULL,
 external_id VARCHAR(80) NOT NULL,
 parent_external_id VARCHAR(80) NULL,
 name VARCHAR(255) NOT NULL,
 provider_status VARCHAR(64) NOT NULL,
 effective_status VARCHAR(64) NOT NULL,
 source_updated_at VARCHAR(64) NULL,
 source_hash CHAR(64) NOT NULL,
 imported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,account_id,object_type,external_id),
 CONSTRAINT fk_mkt_object_account FOREIGN KEY(organization_id,account_id)
 REFERENCES mkt_paid_accounts(organization_id,account_id),
 CONSTRAINT ck_mkt_object_type CHECK(object_type IN ('campaign','adset','ad'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_paid_insight_snapshots (
 organization_id VARCHAR(190) NOT NULL,
 account_id CHAR(32) NOT NULL,
 grain VARCHAR(16) NOT NULL,
 external_id VARCHAR(80) NOT NULL,
 report_date DATE NOT NULL,
 currency CHAR(3) NOT NULL,
 timezone VARCHAR(64) NOT NULL,
 attribution_key VARCHAR(128) NOT NULL,
 spend_minor BIGINT UNSIGNED NOT NULL,
 impressions BIGINT UNSIGNED NOT NULL,
 clicks BIGINT UNSIGNED NOT NULL,
 source_hash CHAR(64) NOT NULL,
 source_payload JSON NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'PROVISIONAL',
 fetched_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,account_id,grain,external_id,report_date,currency,attribution_key),
 KEY ix_mkt_insight_date(organization_id,account_id,report_date,grain),
 CONSTRAINT fk_mkt_insight_account FOREIGN KEY(organization_id,account_id)
 REFERENCES mkt_paid_accounts(organization_id,account_id),
 CONSTRAINT ck_mkt_insight_grain CHECK(grain IN ('account','campaign','adset','ad'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_paid_sync_checkpoints (
 organization_id VARCHAR(190) NOT NULL,
 account_id CHAR(32) NOT NULL,
 stream VARCHAR(32) NOT NULL,
 cursor_ref VARCHAR(512) NULL,
 status VARCHAR(24) NOT NULL,
 last_success_at DATETIME(6) NULL,
 last_error_code VARCHAR(80) NULL,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 PRIMARY KEY(organization_id,account_id,stream)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations(migration) VALUES('20261118_000156_marketing_core_paid_read');
