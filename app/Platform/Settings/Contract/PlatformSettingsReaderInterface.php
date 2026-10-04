<?php
declare(strict_types=1);

namespace Platform\Settings\Contract;

interface PlatformSettingsReaderInterface
{
    public function value(string $organizationId, string $namespace, string $key, mixed $fallback = null): mixed;
    public function secret(string $organizationId, string $namespace, string $key, ?string $fallback = null): ?string;
    /** @return array<string,mixed> */
    public function namespace(string $organizationId, string $namespace): array;
}
