<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Platform Settings: tenant-scoped settings plus encrypted secrets storage.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL only.');

        $this->addSql(<<<'SQL'
CREATE TABLE cos_platform_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    namespace VARCHAR(128) NOT NULL,
    setting_key VARCHAR(128) NOT NULL,
    value_type VARCHAR(16) NOT NULL,
    value_json JSON NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    updated_by VARCHAR(128) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_platform_setting (organization_id, namespace, setting_key),
    KEY idx_cos_platform_setting_namespace (organization_id, namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->addSql(<<<'SQL'
CREATE TABLE cos_platform_secrets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id VARCHAR(64) NOT NULL,
    namespace VARCHAR(128) NOT NULL,
    secret_key VARCHAR(128) NOT NULL,
    ciphertext LONGTEXT NOT NULL,
    nonce VARCHAR(255) NOT NULL,
    encryption_version INT NOT NULL,
    key_id VARCHAR(128) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    updated_by VARCHAR(128) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cos_platform_secret (organization_id, namespace, secret_key),
    KEY idx_cos_platform_secret_namespace (organization_id, namespace)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS cos_platform_secrets');
        $this->addSql('DROP TABLE IF EXISTS cos_platform_settings');
    }
}
