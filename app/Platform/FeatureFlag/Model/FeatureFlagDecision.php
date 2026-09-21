<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

final readonly class FeatureFlagDecision
{
    public function __construct(
        public FeatureFlagKey $key,
        public bool $enabled,
        public FeatureFlagDecisionReason $reason,
        public ?int $bucket = null,
        public ?int $rolloutPercentage = null,
    ) {
    }
}
