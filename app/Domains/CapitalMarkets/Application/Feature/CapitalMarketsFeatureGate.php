<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Feature;

use Platform\FeatureFlag\Model\FeatureFlagContext;
use Platform\FeatureFlag\Model\FeatureFlagKey;
use Platform\FeatureFlag\Service\FeatureFlagResolver;

final readonly class CapitalMarketsFeatureGate
{
    public function __construct(private FeatureFlagResolver $flags){}

    public function enabled(CapitalMarketsFeatureFlag $flag,string $organizationId,?string $userId=null):bool
    {
        return $this->flags->enabled(
            new FeatureFlagKey($flag->value),
            new FeatureFlagContext($organizationId,$userId,'server'),
        );
    }
}
