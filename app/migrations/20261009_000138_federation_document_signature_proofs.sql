-- Trusted provider attestation inbox, never populated by generic Documents sign API.
-- The only permitted ingestion path must verify a pinned provider RSA public key
-- and signer, tenant, Run, exact version, file hash and chronological proof.
CREATE TABLE IF NOT EXISTS cos_federation_document_signature_proofs (
    organization_id VARCHAR(64) NOT NULL,
    signature_id VARCHAR(80) NOT NULL,
    proof_id VARCHAR(191) NOT NULL,
    run_id VARCHAR(64) NOT NULL,
    document_id VARCHAR(80) NOT NULL,
    signer_id VARCHAR(191) NOT NULL,
    document_version_id VARCHAR(80) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    issuer VARCHAR(191) NOT NULL,
    signature_reference VARCHAR(255) NOT NULL,
    proof_payload_sha256 CHAR(64) NOT NULL,
    signed_at_provider DATETIME(6) NOT NULL,
    verified_at DATETIME(6) NOT NULL,
    consumed_action_id CHAR(32) NULL,
    PRIMARY KEY (organization_id, signature_id),
    UNIQUE KEY uq_fed_document_proof_id (organization_id, issuer, proof_id),
    UNIQUE KEY uq_fed_document_proof_action (organization_id, consumed_action_id),
    KEY ix_fed_document_proof_run (organization_id, run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20261009_000138_federation_document_signature_proofs');
