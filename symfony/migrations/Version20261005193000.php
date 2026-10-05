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
            ADD max_parallel_qa INT NOT NULL DEFAULT 2 AFTER max_parallel_reviews");

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
        $this->addSql('ALTER TABLE cos_engineering_domains DROP max_parallel_qa, DROP max_parallel_reviews, DROP max_parallel_developers');
    }
}
