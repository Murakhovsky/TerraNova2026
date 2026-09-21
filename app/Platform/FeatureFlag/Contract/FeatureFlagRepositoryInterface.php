<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Contract;

use DateTimeImmutable;
use Platform\FeatureFlag\Model\FeatureFlagDefinition;
use Platform\FeatureFlag\Model\FeatureFlagKey;
use Platform\FeatureFlag\Model\FeatureFlagOverride;

interface FeatureFlagRepositoryInterface
{
    public function definition(FeatureFlagKey $key): ?FeatureFlagDefinition;

    public function override(
        FeatureFlagKey $key,
        string $organizationId,
        ?string $userId,
        DateTimeImmutable $at,
    ): ?FeatureFlagOverride;
}
