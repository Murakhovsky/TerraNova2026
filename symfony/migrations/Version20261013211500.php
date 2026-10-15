<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal for the Capital Markets evidence-only runtime agent workflow.
 * Unique per organization/correlation and fenced by owner token.
 */
final class Version20261013211500 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Capital Markets runtime agent cycle durable checkpoints and idempotent run ownership.';
    }

    public function up(Schema $schema):void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Capital Markets agent cycle journal requires MySQL.');
        $this->addSql("CREATE TABLE tn_capital_market_agent_cycles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            organization_id VARCHAR(190) NOT NULL,
            correlation_id VARCHAR(100) NOT NULL,
            opportunity_id VARCHAR(190) NOT NULL,
            input_hash CHAR(64) NOT NULL,
            owner_token CHAR(32) NOT NULL,
            state VARCHAR(40) NOT NULL,
            stages_json JSON NOT NULL,
            result_json JSON NULL,
            attempt SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id),
            UNIQUE KEY uq_cm_agent_cycle_organization_correlation (organization_id,correlation_id),
            KEY idx_cm_agent_cycle_organization_state (organization_id,state,updated_at),
            CONSTRAINT chk_cm_agent_cycle_state
              CHECK (state IN ('RUNNING','FAILED','BLOCKED','AWAITING_EXECUTION_EVIDENCE','REVIEW_REQUIRED'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Schema $schema):void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform,
            'Capital Markets agent cycle journal requires MySQL.');
        $this->addSql('DROP TABLE tn_capital_market_agent_cycles');
    }
}
