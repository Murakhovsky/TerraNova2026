<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260920143300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Database cutover Wave 0: canonical module runtime tables and cutover journal.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'MySQL',
            'This migration can only be executed safely on MySQL.',
        );

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cos_database_cutover_journal (
    cutover_id VARCHAR(96) NOT NULL,
    status VARCHAR(24) NOT NULL,
    source_rows INT UNSIGNED NOT NULL DEFAULT 0,
    target_rows INT UNSIGNED NOT NULL DEFAULT 0,
    details_json JSON NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cutover_id),
    KEY idx_cos_database_cutover_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cos_organization_modules (
    organization_id VARCHAR(40) NOT NULL,
    module_id VARCHAR(80) NOT NULL,
    enabled TINYINT(1) NOT NULL,
    configuration_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, module_id),
    KEY idx_cos_organization_modules_enabled (organization_id, enabled, module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS cos_module_installations (
    organization_id VARCHAR(64) NOT NULL,
    module_id VARCHAR(64) NOT NULL,
    status ENUM('INSTALLED', 'UNINSTALLED') NOT NULL DEFAULT 'INSTALLED',
    installed_version VARCHAR(32) NOT NULL,
    schema_version VARCHAR(32) NOT NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id, module_id),
    KEY idx_cos_module_installations_status (organization_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cos_module_installations');
        $this->addSql('DROP TABLE IF EXISTS cos_organization_modules');
        $this->addSql('DROP TABLE IF EXISTS cos_database_cutover_journal');
    }
}
