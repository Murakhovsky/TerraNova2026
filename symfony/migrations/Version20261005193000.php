<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering Runtime V2 completion: stage concurrency limits and durable Domain orchestration event ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration can only be executed safely on MySQL.');

        $this->addSql("ALTER TABLE cos_engineering_domains
            ADD max_parallel_developers INT NOT NULL DEFAULT 2 AFTER max_parallel_features,
            ADD max_parallel_reviews INT NOT NULL DEFAULT 2 AFTER max_parallel_developers,
            ADD max_parallel_qa INT NOT NULL DEFAULT 2 AFTER max_parallel_reviews,
            ADD max_feature_retries INT NOT NULL DEFAULT 3 AFTER max_parallel_qa,
            ADD max_domain_integration_cycles INT NOT NULL DEFAULT 3 AFTER max_feature_retries,
            ADD context_budget INT NOT NULL DEFAULT 120000 AFTER max_domain_integration_cycles,
            ADD token_budget BIGINT NOT NULL DEFAULT 1000000 AFTER context_budget,
            ADD cost_budget DECIMAL(14,6) NOT NULL DEFAULT 25.000000 AFTER token_budget");

        $this->addSql("ALTER TABLE cos_engineering_domain_features
            ADD engineering_feature_history JSON NULL AFTER engineering_feature_id");

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_human_decisions (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    gate_type VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL,
    resume_status VARCHAR(48) NOT NULL,
    question TEXT NOT NULL,
    reason TEXT NOT NULL,
    options_payload JSON NOT NULL,
    evidence_payload JSON NOT NULL,
    answer_payload JSON NULL,
    requested_by VARCHAR(128) NOT NULL,
    answered_by VARCHAR(128) NULL,
    created_at DATETIME(6) NOT NULL,
    answered_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    KEY idx_cos_eng_domain_decision_open (domain_id, status, created_at),
    KEY idx_cos_eng_domain_decision_gate (domain_id, gate_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_artifact_dependencies (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    source_artifact_id VARCHAR(36) NOT NULL,
    target_artifact_id VARCHAR(36) NOT NULL,
    relationship VARCHAR(64) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_artifact_dependency (domain_id, source_artifact_id, target_artifact_id, relationship),
    KEY idx_cos_eng_domain_artifact_dependency_target (domain_id, target_artifact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_engineering_domain_runtime_events (
    id VARCHAR(36) NOT NULL,
    domain_id VARCHAR(36) NOT NULL,
    organization_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    feature_key VARCHAR(96) NULL,
    actor VARCHAR(128) NOT NULL DEFAULT 'SYSTEM',
    reason VARCHAR(500) NULL,
    artifact_id VARCHAR(36) NULL,
    repository_revision VARCHAR(128) NULL,
    result VARCHAR(64) NULL,
    payload JSON NOT NULL,
    correlation_id VARCHAR(128) NOT NULL,
    dedupe_key VARCHAR(191) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_eng_domain_runtime_event_dedupe (domain_id, dedupe_key),
    KEY idx_cos_eng_domain_runtime_event_time (domain_id, created_at),
    KEY idx_cos_eng_domain_runtime_event_type (domain_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'This migration can only be executed safely on MySQL.');

        $this->addSql('DROP TABLE cos_engineering_domain_runtime_events');
        $this->addSql('DROP TABLE cos_engineering_domain_human_decisions');
        $this->addSql('DROP TABLE cos_engineering_domain_artifact_dependencies');
        $this->addSql('ALTER TABLE cos_engineering_domain_features DROP engineering_feature_history');
        $this->addSql('ALTER TABLE cos_engineering_domains DROP cost_budget, DROP token_budget, DROP context_budget, DROP max_domain_integration_cycles, DROP max_feature_retries, DROP max_parallel_qa, DROP max_parallel_reviews, DROP max_parallel_developers');
    }
}
