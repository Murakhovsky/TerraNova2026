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

        $this->addSql("ALTER TABLE cos_engineering_domain_agent_runs
            ADD runtime_id VARCHAR(36) NULL AFTER id,
            ADD feature_id VARCHAR(36) NULL AFTER domain_id,
            ADD state VARCHAR(48) NULL AFTER status,
            ADD started_at DATETIME(6) NULL AFTER state,
            ADD finished_at DATETIME(6) NULL AFTER started_at,
            ADD input_payload JSON NULL AFTER finished_at,
            ADD output_payload JSON NULL AFTER input_payload,
            ADD artifact_payload JSON NULL AFTER output_payload,
            ADD repository_revision VARCHAR(128) NULL AFTER artifact_payload,
            ADD cost_amount DECIMAL(14,6) NULL AFTER repository_revision,
            ADD token_usage JSON NULL AFTER cost_amount,
            ADD errors_payload JSON NULL AFTER token_usage,
            ADD INDEX idx_cos_eng_domain_run_runtime (runtime_id),
            ADD INDEX idx_cos_eng_domain_run_state (domain_id, state, started_at)");

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
        $this->addSql('ALTER TABLE cos_engineering_domain_agent_runs DROP INDEX idx_cos_eng_domain_run_state, DROP INDEX idx_cos_eng_domain_run_runtime, DROP errors_payload, DROP token_usage, DROP cost_amount, DROP repository_revision, DROP artifact_payload, DROP output_payload, DROP input_payload, DROP finished_at, DROP started_at, DROP state, DROP feature_id, DROP runtime_id');
        $this->addSql('ALTER TABLE cos_engineering_domain_features DROP engineering_feature_history');
        $this->addSql('ALTER TABLE cos_engineering_domains DROP cost_budget, DROP token_budget, DROP context_budget, DROP max_domain_integration_cycles, DROP max_feature_retries, DROP max_parallel_qa, DROP max_parallel_reviews, DROP max_parallel_developers');
    }
}
