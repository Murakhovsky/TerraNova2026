<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use InvalidArgumentException;

/**
 * Tenant-scoped preference snapshot. Identity MUST come from TenantContext;
 * persisted selections do not grant access to capabilities or commands.
 */
final readonly class ExperienceProfile
{
    public const SCHEMA_VERSION = '1.0.0';

    public function __construct(
        public string $organizationId,
        public string $userId,
        public ExperienceMode $defaultMode = ExperienceMode::Result,
        public int $version = 1,
    ) {
        if (trim($organizationId) === '' || trim($userId) === '' || $version < 1) {
            throw new InvalidArgumentException('Invalid tenant-scoped ExperienceProfile.');
        }
    }
}
