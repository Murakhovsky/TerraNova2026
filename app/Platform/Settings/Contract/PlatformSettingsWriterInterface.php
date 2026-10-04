<?php
declare(strict_types=1);

namespace Platform\Settings\Contract;

interface PlatformSettingsWriterInterface
{
    public function put(string $organizationId, string $namespace, string $key, mixed $value, string $type, string $actorId, string $correlationId): void;
    public function putSecret(string $organizationId, string $namespace, string $key, string $value, string $actorId, string $correlationId): void;
    public function delete(string $organizationId, string $namespace, string $key, string $actorId, string $correlationId): void;
    public function deleteSecret(string $organizationId, string $namespace, string $key, string $actorId, string $correlationId): void;
}
