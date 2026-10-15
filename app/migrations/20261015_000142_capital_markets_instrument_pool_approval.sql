-- Instrument Pool approvals affect canonical Capital Markets tables only; no shadow registry.
CREATE TABLE IF NOT EXISTS tn_capital_market_instrument_pool_changes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 organization_id VARCHAR(190) NOT NULL,
 snapshot_id BIGINT UNSIGNED NOT NULL,
 provider VARCHAR(24) NOT NULL,
 venue_id VARCHAR(190) NOT NULL,
 venue_symbol VARCHAR(120) NOT NULL,
 action VARCHAR(24) NOT NULL,
 proposed_payload JSON NOT NULL,
 evidence_digest CHAR(64) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 proposed_by BIGINT UNSIGNED NOT NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 review_reason VARCHAR(500) NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
 result_json JSON NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 decided_at DATETIME(6) NULL,
 PRIMARY KEY(id),
 UNIQUE KEY uq_cm_pool_proposal (organization_id,snapshot_id,venue_symbol),
 KEY ix_cm_pool_review (organization_id,status,created_at),
 KEY ix_cm_pool_venue (organization_id,venue_id,venue_symbol),
 CONSTRAINT fk_cm_pool_change_snapshot FOREIGN KEY (snapshot_id)
   REFERENCES tn_capital_market_instrument_pool_snapshots(id)
   ON UPDATE RESTRICT ON DELETE RESTRICT,
 CONSTRAINT fk_cm_pool_change_venue FOREIGN KEY (organization_id,venue_id)
   REFERENCES tn_capital_market_venues(organization_id,venue_id)
   ON UPDATE RESTRICT ON DELETE RESTRICT,
 CONSTRAINT chk_cm_pool_change_action CHECK(action IN ('CREATE','LINK','STATUS')),
 CONSTRAINT chk_cm_pool_change_status CHECK(status IN ('PENDING','APPLIED','REJECTED')),
 CONSTRAINT chk_cm_pool_change_approval CHECK(reviewed_by IS NULL OR reviewed_by <> proposed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO capital_market_user_capabilities
 (organization_id,user_id,capability,status,granted_by)
SELECT u.organization_id,u.id,c.capability,'ACTIVE','cm-pool-approval-install'
FROM tn_users u
CROSS JOIN (
 SELECT 'capital_markets.instrument_pool.view' capability UNION ALL
 SELECT 'capital_markets.instrument_pool.propose' UNION ALL
 SELECT 'capital_markets.instrument_pool.approve'
) c
WHERE u.role='admin' AND u.status='active';

INSERT IGNORE INTO tn_migrations(migration)
VALUES('20261015_000142_capital_markets_instrument_pool_approval');
