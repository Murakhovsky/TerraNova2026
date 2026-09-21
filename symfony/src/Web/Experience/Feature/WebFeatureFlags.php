<?php

declare(strict_types=1);

namespace App\Web\Experience\Feature;

use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Platform\FeatureFlag\Model\FeatureFlagContext;
use Platform\FeatureFlag\Model\FeatureFlagDecision;
use Platform\FeatureFlag\Model\FeatureFlagKey;
use Platform\FeatureFlag\Service\FeatureFlagResolver;

final readonly class WebFeatureFlags
{
    public function __construct(
        private TenantContextProviderInterface $tenants,
        private FeatureFlagResolver $flags,
        private string $defaultOrganizationId,
    ) {
    }

    public function enabled(string $key, string $surface = 'web_desktop'): bool
    {
        return $this->decision($key, $surface)->enabled;
    }

    public function decision(string $key, string $surface = 'web_desktop'): FeatureFlagDecision
    {
        $tenant = $this->tenants->current();
        $organizationId = $tenant?->organizationId()->value() ?: $this->defaultOrganizationId;
        $userId = $tenant?->userId()->value();

        return $this->flags->decide(
            new FeatureFlagKey($key),
            new FeatureFlagContext(
                organizationId: $organizationId,
                userId: $userId,
                surface: $surface,
            ),
        );
    }
}
