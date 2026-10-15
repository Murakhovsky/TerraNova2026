<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Additive audit / two-person approvals. No changes to legacy executors. */
final class Version20261015151000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Two-person, tenant-scoped, CAS fenced Workflow uncertainty reconciliation ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL required.');
        $this->addSql(<<<'SQL'
CREATE TABLE cos_workflow_recovery_decisions (
    organization_id VARCHAR(190) NOT NULL,
    request_id CHAR(32) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    step_id VARCHAR(64) NOT NULL,
    run_revision INT UNSIGNED NOT NULL,
    step_revision INT UNSIGNED NOT NULL,
    resolution VARCHAR(40) NOT NULL,
    receipt_reference VARCHAR(255) NULL,
    reason TEXT NOT NULL,
    requested_by VARCHAR(190) NOT NULL,
    approved_by VARCHAR(190) NULL,
    status VARCHAR(24) NOT NULL,
    requested_at DATETIME(6) NOT NULL,
    approved_at DATETIME(6) NULL,
    applied_at DATETIME(6) NULL,
    PRIMARY KEY (organization_id,request_id),
    KEY idx_workflow_recovery_pending (organization_id,status,requested_at),
    KEY idx_workflow_recovery_run (organization_id,run_id,step_id,requested_at),
    CONSTRAINT fk_recovery_workflow_run FOREIGN KEY (organization_id,run_id)
      REFERENCES cos_workflow_runs (organization_id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE cos_workflow_recovery_events (
    organization_id VARCHAR(190) NOT NULL,
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id CHAR(32) NOT NULL,
    run_id VARCHAR(190) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    actor_id VARCHAR(190) NOT NULL,
    run_revision INT UNSIGNED NOT NULL,
    step_revision INT UNSIGNED NOT NULL,
    detail_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_recovery_event_tenant_request (organization_id,request_id,id),
    CONSTRAINT fk_recovery_event_decision FOREIGN KEY (organization_id,request_id)
      REFERENCES cos_workflow_recovery_decisions (organization_id,request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }
    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Recovery audit evidence must never be dropped automatically.');
    }
}
