<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering Runtime V2.0: complex Domain development initiatives, capability/feature DAG, contracts, artifacts and audit.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domains (
    id CHAR(36) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    domain_key VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    status VARCHAR(64) NOT NULL,
    version VARCHAR(32) NOT NULL,
    master_specification JSON NOT NULL,
    business_goal LONGTEXT NOT NULL,
    scope_json JSON NOT NULL,
    out_of_scope_json JSON NOT NULL,
    architecture_json JSON NULL,
    constitution_json JSON NULL,
    domain_acceptance_criteria JSON NOT NULL,
    architecture_version INT NOT NULL DEFAULT 0,
    qa_status VARCHAR(32) NOT NULL,
    release_status VARCHAR(32) NOT NULL,
    target_repository VARCHAR(255) NOT NULL,
    target_branch VARCHAR(255) NOT NULL,
    created_by VARCHAR(128) NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_org_key (organization_id, domain_key),
    KEY idx_cos_eng_domain_org_status (organization_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_capabilities (
    id CHAR(36) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    capability_key VARCHAR(128) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    kind VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_capability (domain_id, capability_key),
    KEY idx_cos_eng_domain_capability_status (domain_id, status),
    CONSTRAINT fk_cos_eng_domain_capability_domain FOREIGN KEY (domain_id) REFERENCES cos_engineering_domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_features (
    id CHAR(36) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    capability_id CHAR(36) NULL,
    feature_key VARCHAR(128) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT NOT NULL,
    kind VARCHAR(32) NOT NULL,
    risk VARCHAR(16) NOT NULL,
    priority VARCHAR(4) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(32) NOT NULL,
    engineering_feature_id CHAR(36) NULL,
    architecture_version INT NOT NULL DEFAULT 0,
    owned_paths JSON NOT NULL,
    shared_paths JSON NOT NULL,
    forbidden_paths JSON NOT NULL,
    last_error LONGTEXT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_feature (domain_id, feature_key),
    UNIQUE KEY uniq_cos_eng_domain_engineering_feature (engineering_feature_id),
    KEY idx_cos_eng_domain_feature_status (domain_id, status, priority),
    KEY idx_cos_eng_domain_feature_capability (capability_id),
    CONSTRAINT fk_cos_eng_domain_feature_domain FOREIGN KEY (domain_id) REFERENCES cos_engineering_domains (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_domain_feature_capability FOREIGN KEY (capability_id) REFERENCES cos_engineering_domain_capabilities (id) ON DELETE SET NULL,
    CONSTRAINT fk_cos_eng_domain_feature_engineering FOREIGN KEY (engineering_feature_id) REFERENCES cos_engineering_features (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_dependencies (
    id CHAR(36) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    feature_id CHAR(36) NOT NULL,
    depends_on_feature_id CHAR(36) NOT NULL,
    dependency_type VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_dependency (feature_id, depends_on_feature_id, dependency_type),
    KEY idx_cos_eng_domain_dependency_domain (domain_id),
    KEY idx_cos_eng_domain_dependency_upstream (depends_on_feature_id),
    CONSTRAINT fk_cos_eng_domain_dependency_domain FOREIGN KEY (domain_id) REFERENCES cos_engineering_domains (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_domain_dependency_feature FOREIGN KEY (feature_id) REFERENCES cos_engineering_domain_features (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_domain_dependency_upstream FOREIGN KEY (depends_on_feature_id) REFERENCES cos_engineering_domain_features (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_contracts (
    id CHAR(36) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    contract_key VARCHAR(191) NOT NULL,
    contract_type VARCHAR(64) NOT NULL,
    version VARCHAR(32) NOT NULL,
    compatibility VARCHAR(32) NOT NULL,
    producer_feature_id CHAR(36) NULL,
    consumers JSON NOT NULL,
    schema_json JSON NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_contract (domain_id, contract_key),
    KEY idx_cos_eng_domain_contract_type (domain_id, contract_type),
    CONSTRAINT fk_cos_eng_domain_contract_domain FOREIGN KEY (domain_id) REFERENCES cos_engineering_domains (id) ON DELETE CASCADE,
    CONSTRAINT fk_cos_eng_domain_contract_producer FOREIGN KEY (producer_feature_id) REFERENCES cos_engineering_domain_features (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_artifacts (
    id CHAR(36) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    type VARCHAR(64) NOT NULL,
    version INT NOT NULL,
    status VARCHAR(32) NOT NULL,
    content JSON NOT NULL,
    content_hash CHAR(64) NOT NULL,
    architecture_version INT NOT NULL,
    created_by VARCHAR(128) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_artifact_version (domain_id, type, version),
    KEY idx_cos_eng_domain_artifact_active (domain_id, type, status),
    KEY idx_cos_eng_domain_artifact_hash (content_hash),
    CONSTRAINT fk_cos_eng_domain_artifact_domain FOREIGN KEY (domain_id) REFERENCES cos_engineering_domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_audit (
    id CHAR(36) NOT NULL,
    domain_id CHAR(36) NOT NULL,
    event VARCHAR(128) NOT NULL,
    actor VARCHAR(128) NULL,
    payload JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_domain_audit_domain_time (domain_id, created_at),
    KEY idx_cos_eng_domain_audit_event (event),
    CONSTRAINT fk_cos_eng_domain_audit_domain FOREIGN KEY (domain_id) REFERENCES cos_engineering_domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('DROP TABLE cos_engineering_domain_audit');
        $this->addSql('DROP TABLE cos_engineering_domain_artifacts');
        $this->addSql('DROP TABLE cos_engineering_domain_contracts');
        $this->addSql('DROP TABLE cos_engineering_domain_dependencies');
        $this->addSql('DROP TABLE cos_engineering_domain_features');
        $this->addSql('DROP TABLE cos_engineering_domain_capabilities');
        $this->addSql('DROP TABLE cos_engineering_domains');
    }
}
