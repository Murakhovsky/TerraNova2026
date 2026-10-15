-- Human evidence is a review record, not provider-side entitlement verification.
-- No credential material, private documents, or synthetic soak results are stored.
CREATE TABLE IF NOT EXISTS tn_capital_market_production_attestations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(190) NOT NULL,
    evidence_kind VARCHAR(40) NOT NULL,
    subject_id VARCHAR(190) NOT NULL,
    evidence_url VARCHAR(800) NOT NULL,
    evidence_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    valid_until DATETIME(6) NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
    decided_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(id),
    KEY ix_cm_production_attestation (organization_id,evidence_kind,subject_id,status,valid_until),
    CONSTRAINT chk_cm_production_att_kind CHECK (evidence_kind IN
        ('VENUE_ENTITLEMENT','MASSIVE_NBBO_ENTITLEMENT','ISSUER_IDENTITY')),
    CONSTRAINT chk_cm_production_att_status CHECK (status IN ('PENDING','APPROVED','REJECTED')),
    CONSTRAINT chk_cm_production_att_separation CHECK (reviewed_by IS NULL OR reviewed_by<>submitted_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations(migration)
VALUES('20261015_000144_capital_markets_production_attestations');
