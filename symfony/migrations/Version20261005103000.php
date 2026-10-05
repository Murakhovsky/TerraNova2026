<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engineering runtime truth: persist heartbeat, runtime health, stalled timestamp and runtime reason.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql("ALTER TABLE cos_engineering_workflows
            ADD heartbeat_at DATETIME(6) NULL AFTER last_activity_at,
            ADD health_status VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN' AFTER heartbeat_at,
            ADD stalled_at DATETIME(6) NULL AFTER health_status,
            ADD runtime_reason TEXT NULL AFTER stalled_at");
        $this->addSql("UPDATE cos_engineering_workflows
            SET heartbeat_at = last_activity_at,
                health_status = CASE
                    WHEN status IN ('COMPLETED','CANCELLED','FAILED') THEN 'TERMINAL'
                    WHEN current_state IN ('HUMAN_DECISION_REQUIRED','READY_FOR_HUMAN_APPROVAL','BLOCKED','ESCALATED') THEN 'WAITING'
                    ELSE 'UNKNOWN'
                END");
        $this->addSql('CREATE INDEX idx_cos_eng_workflow_runtime_health ON cos_engineering_workflows (health_status, heartbeat_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql('DROP INDEX idx_cos_eng_workflow_runtime_health ON cos_engineering_workflows');
        $this->addSql('ALTER TABLE cos_engineering_workflows DROP heartbeat_at, DROP health_status, DROP stalled_at, DROP runtime_reason');
    }
}
