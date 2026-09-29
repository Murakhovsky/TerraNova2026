-- Wave 10 Platform Documents runtime fixture.

CREATE TABLE IF NOT EXISTS cos_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    document_id VARCHAR(80) NOT NULL,
    title VARCHAR(220) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    current_version_id VARCHAR(80) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    archived_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_documents_id (organization_id,document_id)
);

CREATE TABLE IF NOT EXISTS cos_document_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    file_id VARCHAR(80) NOT NULL,
    storage_key VARCHAR(700) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_document_file (organization_id,file_id),
    UNIQUE KEY uq_cos_document_storage (organization_id,storage_key(191))
);

CREATE TABLE IF NOT EXISTS cos_document_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    version_id VARCHAR(80) NOT NULL,
    document_id VARCHAR(80) NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    file_id VARCHAR(80) NOT NULL,
    source_type VARCHAR(32) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_document_version (organization_id,version_id),
    UNIQUE KEY uq_cos_document_version_number (organization_id,document_id,version_number)
);

CREATE TABLE IF NOT EXISTS cos_document_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    relation_id VARCHAR(80) NOT NULL,
    document_id VARCHAR(80) NOT NULL,
    related_type VARCHAR(64) NOT NULL,
    related_id VARCHAR(191) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_document_relation (organization_id,relation_id),
    UNIQUE KEY uq_cos_document_relation_business (organization_id,document_id,related_type,related_id)
);

CREATE TABLE IF NOT EXISTS cos_document_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    template_id VARCHAR(80) NOT NULL,
    name VARCHAR(220) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    filename_pattern VARCHAR(191) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_document_template (organization_id,template_id)
);

CREATE TABLE IF NOT EXISTS cos_document_signatures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    signature_id VARCHAR(80) NOT NULL,
    document_id VARCHAR(80) NOT NULL,
    signer_id VARCHAR(191) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'requested',
    requested_by BIGINT UNSIGNED NOT NULL,
    requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    signed_by VARCHAR(191) NULL,
    signed_by_actor_id BIGINT UNSIGNED NULL,
    signature_reference VARCHAR(255) NULL,
    signed_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_cos_document_signature (organization_id,signature_id)
);

CREATE TABLE IF NOT EXISTS cos_document_operation_receipts (
    organization_id VARCHAR(64) NOT NULL,
    operation_type VARCHAR(80) NOT NULL,
    idempotency_key VARCHAR(191) NOT NULL,
    payload_fingerprint CHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (organization_id,operation_type,idempotency_key)
);

INSERT INTO cos_document_templates
    (organization_id,template_id,name,mime_type,body,filename_pattern,active)
VALUES
    ('default','TPL-WAVE10-CONTRACT','Wave 10 Contract','text/plain',
     'Contract for {{client}}','contract-{{client}}.txt',1)
ON DUPLICATE KEY UPDATE
    name=VALUES(name),mime_type=VALUES(mime_type),body=VALUES(body),
    filename_pattern=VALUES(filename_pattern),active=VALUES(active);
