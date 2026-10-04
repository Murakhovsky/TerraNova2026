<?php
declare(strict_types=1);

namespace Platform\Settings\Contract;

interface PlatformSettingsRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function setting(string $organizationId, string $namespace, string $key): ?array;
    /** @return array<string,mixed>|null */
    public function secret(string $organizationId, string $namespace, string $key): ?array;
    /** @return list<array<string,mixed>> */
    public function settingsForNamespace(string $organizationId, string $namespace): array;
    public function upsertSetting(string $organizationId, string $namespace, string $key, string $type, string $encodedValue, string $actorId): void;
    public function upsertSecret(string $organizationId, string $namespace, string $key, string $ciphertext, string $nonce, int $encryptionVersion, string $keyId, string $actorId): void;
    public function deleteSetting(string $organizationId, string $namespace, string $key): void;
    public function deleteSecret(string $organizationId, string $namespace, string $key): void;
}
