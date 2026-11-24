CREATE TABLE IF NOT EXISTS cos_finance_paid_document_reviews (
    organization_id VARCHAR(190) NOT NULL,
    review_ref VARCHAR(40) NOT NULL,
    reservation_id VARCHAR(40) NOT NULL,
    document_ref VARCHAR(80) NOT NULL,
    document_version_ref VARCHAR(80) NOT NULL,
    snapshot_ref VARCHAR(40) NOT NULL,
    source_revision INT UNSIGNED NOT NULL,
    document_sha256 CHAR(64) NOT NULL,
    source_hash CHAR(64) NOT NULL,
    reviewer_id VARCHAR(190) NOT NULL,
    candidate_hash CHAR(64) NOT NULL,
    evidence_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,review_ref),
    UNIQUE KEY uq_finance_review_pin (organization_id,reservation_id,document_ref,document_version_ref,snapshot_ref,source_revision),
    CONSTRAINT fk_finance_review_claim FOREIGN KEY (organization_id,reservation_id) REFERENCES cos_finance_paid_exposure_claims(organization_id,reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
