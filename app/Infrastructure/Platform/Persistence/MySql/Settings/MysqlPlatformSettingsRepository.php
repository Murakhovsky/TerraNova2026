<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\Settings;

use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Platform\Settings\Contract\PlatformSettingsRepositoryInterface;

final readonly class MysqlPlatformSettingsRepository implements PlatformSettingsRepositoryInterface
{
    public function __construct(private PdoConnection $database) {}

    public function setting(string $organizationId, string $namespace, string $key): ?array
    {
        return $this->database->fetchOne(
            'SELECT setting_key, value_type, value_json, updated_at, updated_by
             FROM cos_platform_settings
             WHERE organization_id=:organization_id AND namespace=:namespace AND setting_key=:setting_key
             LIMIT 1',
            ['organization_id'=>$organizationId,'namespace'=>$namespace,'setting_key'=>$key],
        );
    }

    public function secret(string $organizationId, string $namespace, string $key): ?array
    {
        return $this->database->fetchOne(
            'SELECT secret_key, ciphertext, nonce, encryption_version, key_id, updated_at, updated_by
             FROM cos_platform_secrets
             WHERE organization_id=:organization_id AND namespace=:namespace AND secret_key=:secret_key
             LIMIT 1',
            ['organization_id'=>$organizationId,'namespace'=>$namespace,'secret_key'=>$key],
        );
    }

    public function settingsForNamespace(string $organizationId, string $namespace): array
    {
        return $this->database->fetchAll(
            'SELECT setting_key, value_type, value_json, updated_at, updated_by
             FROM cos_platform_settings
             WHERE organization_id=:organization_id AND namespace=:namespace
             ORDER BY setting_key',
            ['organization_id'=>$organizationId,'namespace'=>$namespace],
        );
    }

    public function upsertSetting(string $organizationId, string $namespace, string $key, string $type, string $encodedValue, string $actorId): void
    {
        $pdo = $this->database->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO cos_platform_settings (organization_id,namespace,setting_key,value_type,value_json,created_at,updated_at,updated_by)
             VALUES (:organization_id,:namespace,:setting_key,:value_type,:value_json,NOW(6),NOW(6),:updated_by)
             ON DUPLICATE KEY UPDATE value_type=VALUES(value_type), value_json=VALUES(value_json), updated_at=NOW(6), updated_by=VALUES(updated_by)'
        );
        $stmt->execute([
            'organization_id'=>$organizationId,'namespace'=>$namespace,'setting_key'=>$key,
            'value_type'=>$type,'value_json'=>$encodedValue,'updated_by'=>$actorId,
        ]);
    }

    public function upsertSecret(string $organizationId, string $namespace, string $key, string $ciphertext, string $nonce, int $encryptionVersion, string $keyId, string $actorId): void
    {
        $pdo = $this->database->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO cos_platform_secrets (organization_id,namespace,secret_key,ciphertext,nonce,encryption_version,key_id,created_at,updated_at,updated_by)
             VALUES (:organization_id,:namespace,:secret_key,:ciphertext,:nonce,:encryption_version,:key_id,NOW(6),NOW(6),:updated_by)
             ON DUPLICATE KEY UPDATE ciphertext=VALUES(ciphertext), nonce=VALUES(nonce), encryption_version=VALUES(encryption_version), key_id=VALUES(key_id), updated_at=NOW(6), updated_by=VALUES(updated_by)'
        );
        $stmt->execute([
            'organization_id'=>$organizationId,'namespace'=>$namespace,'secret_key'=>$key,
            'ciphertext'=>$ciphertext,'nonce'=>$nonce,'encryption_version'=>$encryptionVersion,
            'key_id'=>$keyId,'updated_by'=>$actorId,
        ]);
    }

    public function deleteSetting(string $organizationId, string $namespace, string $key): void
    {
        $stmt = $this->database->connection()->prepare(
            'DELETE FROM cos_platform_settings WHERE organization_id=:organization_id AND namespace=:namespace AND setting_key=:setting_key'
        );
        $stmt->execute(['organization_id'=>$organizationId,'namespace'=>$namespace,'setting_key'=>$key]);
    }

    public function deleteSecret(string $organizationId, string $namespace, string $key): void
    {
        $stmt = $this->database->connection()->prepare(
            'DELETE FROM cos_platform_secrets WHERE organization_id=:organization_id AND namespace=:namespace AND secret_key=:secret_key'
        );
        $stmt->execute(['organization_id'=>$organizationId,'namespace'=>$namespace,'secret_key'=>$key]);
    }
}
