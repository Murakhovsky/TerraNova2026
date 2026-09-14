-- Property V0.4.0: canonical identity resolution, external references, field-level provenance and Party relations.
-- Canonical Property identity remains tn_property_assets (organization_id, asset_id).
-- CRM owns Party data; Property stores only opaque party references and relation facts.

CREATE TABLE IF NOT EXISTS tn_property_sources (
    organization_id VARCHAR(64) NOT NULL,
    source_id VARCHAR(80) NOT NULL,
    source_type VARCHAR(32) NOT NULL,
    source_system VARCHAR(80) NOT NULL,
    party_reference VARCHAR(191) NULL,
    trust_level DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, source_id),
    KEY idx_tn_property_sources_system (organization_id, source_system),
    KEY idx_tn_property_sources_party (organization_id, party_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_external_references (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    source_id VARCHAR(80) NOT NULL,
    source_system VARCHAR(80) NOT NULL,
    external_id VARCHAR(191) NOT NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tn_property_external_refs_tenant_id (organization_id, id),
    UNIQUE KEY uq_tn_property_external_refs_identity (organization_id, source_system, external_id),
    KEY idx_tn_property_external_refs_asset (organization_id, asset_id),
    KEY idx_tn_property_external_refs_source (organization_id, source_id),
    CONSTRAINT fk_tn_property_external_refs_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_external_refs_source
        FOREIGN KEY (organization_id, source_id)
        REFERENCES tn_property_sources (organization_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_provenance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    field_path VARCHAR(191) NOT NULL,
    source_id VARCHAR(80) NOT NULL,
    external_reference_id BIGINT UNSIGNED NULL,
    observed_value JSON NOT NULL,
    observed_at TIMESTAMP NULL,
    imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
    verification_status VARCHAR(32) NOT NULL DEFAULT 'unverified',
    PRIMARY KEY (id),
    KEY idx_tn_property_provenance_asset_field (organization_id, asset_id, field_path, imported_at),
    KEY idx_tn_property_provenance_source (organization_id, source_id, imported_at),
    KEY idx_tn_property_provenance_external_ref (organization_id, external_reference_id),
    CONSTRAINT fk_tn_property_provenance_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_provenance_source
        FOREIGN KEY (organization_id, source_id)
        REFERENCES tn_property_sources (organization_id, source_id),
    CONSTRAINT fk_tn_property_provenance_external_ref
        FOREIGN KEY (organization_id, external_reference_id)
        REFERENCES tn_property_external_references (organization_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_party_relations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    asset_id VARCHAR(80) NOT NULL,
    party_reference VARCHAR(191) NOT NULL,
    relation_type VARCHAR(32) NOT NULL,
    relation_status VARCHAR(24) NOT NULL DEFAULT 'active',
    valid_from TIMESTAMP NULL,
    valid_to TIMESTAMP NULL,
    source_id VARCHAR(80) NULL,
    confidence DECIMAL(5,4) NOT NULL DEFAULT 0.5000,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tn_property_party_relations_asset (organization_id, asset_id, relation_type),
    KEY idx_tn_property_party_relations_party (organization_id, party_reference, relation_type),
    KEY idx_tn_property_party_relations_validity (organization_id, asset_id, valid_from, valid_to),
    CONSTRAINT fk_tn_property_party_relations_asset
        FOREIGN KEY (organization_id, asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_tn_property_party_relations_source
        FOREIGN KEY (organization_id, source_id)
        REFERENCES tn_property_sources (organization_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tn_property_identity_resolutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    submission_id BIGINT UNSIGNED NULL,
    candidate_asset_id VARCHAR(80) NULL,
    resolved_asset_id VARCHAR(80) NULL,
    decision VARCHAR(16) NOT NULL,
    score DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
    signals_json JSON NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    KEY idx_tn_property_identity_resolutions_submission (organization_id, submission_id),
    KEY idx_tn_property_identity_resolutions_candidate (organization_id, candidate_asset_id),
    KEY idx_tn_property_identity_resolutions_resolved (organization_id, resolved_asset_id),
    KEY idx_tn_property_identity_resolutions_decision (organization_id, decision, created_at),
    CONSTRAINT fk_tn_property_identity_resolutions_submission
        FOREIGN KEY (organization_id, submission_id)
        REFERENCES tn_property_submissions (organization_id, id),
    CONSTRAINT fk_tn_property_identity_resolutions_candidate
        FOREIGN KEY (organization_id, candidate_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id),
    CONSTRAINT fk_tn_property_identity_resolutions_resolved
        FOREIGN KEY (organization_id, resolved_asset_id)
        REFERENCES tn_property_assets (organization_id, asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tn_migrations (migration)
VALUES ('20260914_000052_property_v040_identity_provenance');
