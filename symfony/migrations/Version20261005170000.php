<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering Runtime V2: Domain initiatives, decomposition DAG, contracts, events, path reservations and domain agent audit.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration can only be executed safely on MySQL.');

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domains (
    id VARCHAR(36) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    domain_key VARCHAR(96) NOT NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(48) NOT NULL,
    version INT NOT NULL DEFAULT 1,
    master_specification LONGTEXT NOT NULL,
    target_repository VARCHAR(255) NOT NULL,
    target_branch VARCHAR(191) NOT NULL DEFAULT 'main',
    max_parallel_features INT NOT NULL DEFAULT 3,
    status_reason TEXT NULL,
    created_by VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_org_key (organization_id, domain_key),
    KEY idx_cos_eng_domain_org_status (organization_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_artifacts (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    type VARCHAR(64) NOT NULL,
    version INT NOT NULL,
    status VARCHAR(24) NOT NULL,
    content JSON NOT NULL,
    content_hash VARCHAR(64) NOT NULL,
    supersedes_artifact_id VARCHAR(36) NULL,
    created_by VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_artifact_version (domain_id, type, version),
    KEY idx_cos_eng_domain_artifact_active (domain_id, type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_capabilities (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    capability_key VARCHAR(96) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    kind VARCHAR(32) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(32) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_capability (domain_id, capability_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_features (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    feature_key VARCHAR(96) NOT NULL,
    capability_key VARCHAR(96) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    kind VARCHAR(32) NOT NULL,
    priority VARCHAR(4) NOT NULL,
    risk VARCHAR(16) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(40) NOT NULL,
    acceptance_criteria JSON NOT NULL,
    owned_paths JSON NOT NULL,
    shared_paths JSON NOT NULL,
    forbidden_paths JSON NOT NULL,
    contracts_consumed JSON NOT NULL,
    contracts_produced JSON NOT NULL,
    engineering_feature_id VARCHAR(36) NULL,
    architecture_version INT NULL,
    contract_snapshot JSON NULL,
    status_reason TEXT NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_feature (domain_id, feature_key),
    UNIQUE KEY uniq_cos_eng_domain_engineering_feature (engineering_feature_id),
    KEY idx_cos_eng_domain_feature_status (domain_id, status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_dependencies (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    feature_key VARCHAR(96) NOT NULL,
    depends_on_key VARCHAR(96) NOT NULL,
    dependency_type VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_dependency (domain_id, feature_key, depends_on_key, dependency_type),
    KEY idx_cos_eng_domain_dependency_required (domain_id, depends_on_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_contracts (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    contract_key VARCHAR(96) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(48) NOT NULL,
    version VARCHAR(32) NOT NULL,
    owner_domain VARCHAR(96) NOT NULL,
    producer VARCHAR(96) NOT NULL,
    consumers JSON NOT NULL,
    schema_payload JSON NOT NULL,
    compatibility VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_contract_version (domain_id, contract_key, version),
    KEY idx_cos_eng_domain_contract_active (domain_id, contract_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_events (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    event_key VARCHAR(96) NOT NULL,
    name VARCHAR(255) NOT NULL,
    version VARCHAR(32) NOT NULL,
    producer VARCHAR(96) NOT NULL,
    consumers JSON NOT NULL,
    payload_schema JSON NOT NULL,
    delivery VARCHAR(32) NOT NULL,
    idempotency TEXT NOT NULL,
    ordering_rule TEXT NOT NULL,
    status VARCHAR(24) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_event_version (domain_id, event_key, version),
    KEY idx_cos_eng_domain_event_active (domain_id, event_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_path_reservations (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    feature_key VARCHAR(96) NOT NULL,
    path VARCHAR(500) NOT NULL,
    reserved_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_reserved_path (domain_id, path),
    KEY idx_cos_eng_domain_reserved_feature (domain_id, feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_agent_runs (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    agent_role VARCHAR(48) NOT NULL,
    status VARCHAR(24) NOT NULL,
    correlation_id VARCHAR(128) NOT NULL,
    provider VARCHAR(64) NULL,
    model VARCHAR(128) NULL,
    usage_payload JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_domain_run_time (domain_id, created_at),
    KEY idx_cos_eng_domain_run_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration can only be executed safely on MySQL.');
        foreach ([
            'cos_engineering_domain_agent_runs',
            'cos_engineering_domain_path_reservations',
            'cos_engineering_domain_events',
            'cos_engineering_domain_contracts',
            'cos_engineering_domain_dependencies',
            'cos_engineering_domain_features',
            'cos_engineering_domain_capabilities',
            'cos_engineering_domain_artifacts',
            'cos_engineering_domains',
        ] as $table) {
            $this->addSql('DROP TABLE '.$table);
        }
    }
}
