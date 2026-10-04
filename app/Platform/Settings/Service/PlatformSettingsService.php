<?php
declare(strict_types=1);

namespace Platform\Settings\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Platform\Settings\Contract\PlatformSettingsReaderInterface;
use Platform\Settings\Contract\PlatformSettingsRepositoryInterface;
use Platform\Settings\Contract\PlatformSettingsWriterInterface;
use Platform\Settings\Contract\SecretEncryptionInterface;

final readonly class PlatformSettingsService implements PlatformSettingsReaderInterface, PlatformSettingsWriterInterface
{
    public function __construct(
        private PlatformSettingsRepositoryInterface $repository,
        private SecretEncryptionInterface $encryption,
        private AuditRepositoryInterface $audit,
    ) {}

    public function value(string $organizationId, string $namespace, string $key, mixed $fallback = null): mixed
    {
        $row = $this->repository->setting($organizationId, $namespace, $key);
        if ($row === null) return $fallback;
        return $this->decode((string) ($row['value_type'] ?? 'string'), (string) ($row['value_json'] ?? 'null'));
    }

    public function secret(string $organizationId, string $namespace, string $key, ?string $fallback = null): ?string
    {
        $row = $this->repository->secret($organizationId, $namespace, $key);
        if ($row === null) return $fallback;

        return $this->encryption->decrypt(
            (string) $row['ciphertext'],
            (string) $row['nonce'],
            (int) $row['encryption_version'],
            (string) $row['key_id'],
            $this->associatedData($organizationId, $namespace, $key),
        );
    }

    public function namespace(string $organizationId, string $namespace): array
    {
        $result = [];
        foreach ($this->repository->settingsForNamespace($organizationId, $namespace) as $row) {
            $result[(string) $row['setting_key']] = $this->decode(
                (string) ($row['value_type'] ?? 'string'),
                (string) ($row['value_json'] ?? 'null'),
            );
        }
        return $result;
    }

    public function put(string $organizationId, string $namespace, string $key, mixed $value, string $type, string $actorId, string $correlationId): void
    {
        $this->assertKey($namespace, $key);
        if (!in_array($type, ['string','int','float','bool','json'], true)) {
            throw new InvalidArgumentException('Unsupported Platform Setting type.');
        }
        $encoded = json_encode($this->normalize($type, $value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->repository->upsertSetting($organizationId, $namespace, $key, $type, $encoded, $actorId);
        $this->audit($organizationId, $actorId, $correlationId, 'SETTING_UPDATED', $namespace.'.'.$key, ['value_type' => $type]);
    }

    public function putSecret(string $organizationId, string $namespace, string $key, string $value, string $actorId, string $correlationId): void
    {
        $this->assertKey($namespace, $key);
        if (trim($value) === '') throw new InvalidArgumentException('Secret value cannot be empty.');

        $encrypted = $this->encryption->encrypt($value, $this->associatedData($organizationId, $namespace, $key));
        $this->repository->upsertSecret(
            $organizationId,
            $namespace,
            $key,
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            $encrypted['encryption_version'],
            $encrypted['key_id'],
            $actorId,
        );
        $this->audit($organizationId, $actorId, $correlationId, 'SECRET_REPLACED', $namespace.'.'.$key, ['secret' => true]);
    }

    public function delete(string $organizationId, string $namespace, string $key, string $actorId, string $correlationId): void
    {
        $this->assertKey($namespace, $key);
        $this->repository->deleteSetting($organizationId, $namespace, $key);
        $this->audit($organizationId, $actorId, $correlationId, 'SETTING_DELETED', $namespace.'.'.$key);
    }

    public function deleteSecret(string $organizationId, string $namespace, string $key, string $actorId, string $correlationId): void
    {
        $this->assertKey($namespace, $key);
        $this->repository->deleteSecret($organizationId, $namespace, $key);
        $this->audit($organizationId, $actorId, $correlationId, 'SECRET_DELETED', $namespace.'.'.$key, ['secret' => true]);
    }

    private function normalize(string $type, mixed $value): mixed
    {
        return match ($type) {
            'string' => (string) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'json' => is_array($value) ? $value : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR),
            default => throw new InvalidArgumentException('Unsupported Platform Setting type.'),
        };
    }

    private function decode(string $type, string $encoded): mixed
    {
        $value = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        return match ($type) {
            'string' => (string) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'json' => $value,
            default => $value,
        };
    }

    private function assertKey(string $namespace, string $key): void
    {
        foreach ([$namespace, $key] as $part) {
            if (!preg_match('/^[a-z][a-z0-9_.-]{0,127}$/', $part)) {
                throw new InvalidArgumentException('Invalid Platform Setting namespace/key.');
            }
        }
    }

    private function associatedData(string $organizationId, string $namespace, string $key): string
    {
        return $organizationId.'|'.$namespace.'|'.$key;
    }

    private function audit(string $organizationId, string $actorId, string $correlationId, string $action, string $subjectId, array $data = []): void
    {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),
            $organizationId,
            'PLATFORM_SETTINGS',
            'USER',
            $actorId,
            'platform_setting',
            $subjectId,
            null,
            ['action' => $action] + $data,
            $correlationId,
            new DateTimeImmutable(),
        ));
    }
}
