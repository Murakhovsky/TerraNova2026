<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering: widen workflow and agent-run trace ids to the Kernel CorrelationId limit (128).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('ALTER TABLE cos_engineering_workflows MODIFY trace_id VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE cos_engineering_agent_runs MODIFY trace_id VARCHAR(128) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('ALTER TABLE cos_engineering_agent_runs MODIFY trace_id VARCHAR(64) NOT NULL');
        $this->addSql('ALTER TABLE cos_engineering_workflows MODIFY trace_id VARCHAR(64) NOT NULL');
    }
}
