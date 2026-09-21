<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Service;

use DateTimeImmutable;
use Platform\FeatureFlag\Contract\FeatureFlagRepositoryInterface;
use Platform\FeatureFlag\Model\FeatureFlagContext;
use Platform\FeatureFlag\Model\FeatureFlagDecision;
use Platform\FeatureFlag\Model\FeatureFlagDecisionReason;
use Platform\FeatureFlag\Model\FeatureFlagKey;
use Platform\FeatureFlag\Model\FeatureFlagOverrideScope;

final readonly class FeatureFlagResolver
{
    public function __construct(
        private FeatureFlagRepositoryInterface $flags,
    ) {
    }

    public function enabled(
        FeatureFlagKey $key,
        FeatureFlagContext $context,
        ?DateTimeImmutable $at = null,
    ): bool {
        return $this->decide($key, $context, $at)->enabled;
    }

    public function decide(
        FeatureFlagKey $key,
        FeatureFlagContext $context,
        ?DateTimeImmutable $at = null,
    ): FeatureFlagDecision {
        $at ??= new DateTimeImmutable();
        $definition = $this->flags->definition($key);

        if ($definition === null) {
            return new FeatureFlagDecision($key, false, FeatureFlagDecisionReason::UnknownFlag);
        }

        if (!$definition->enabled) {
            return new FeatureFlagDecision(
                $key,
                false,
                FeatureFlagDecisionReason::MasterDisabled,
                rolloutPercentage: $definition->rolloutPercentage,
            );
        }

        $override = $this->flags->override(
            $key,
            $context->organizationId,
            $context->userId,
            $at,
        );

        if ($override !== null && $override->activeAt($at)) {
            return new FeatureFlagDecision(
                $key,
                $override->enabled,
                $override->scope === FeatureFlagOverrideScope::User
                    ? FeatureFlagDecisionReason::UserOverride
                    : FeatureFlagDecisionReason::OrganizationOverride,
                rolloutPercentage: $definition->rolloutPercentage,
            );
        }

        if (!$definition->isScheduledAt($at)) {
            return new FeatureFlagDecision(
                $key,
                false,
                FeatureFlagDecisionReason::OutsideSchedule,
                rolloutPercentage: $definition->rolloutPercentage,
            );
        }

        if ($definition->rolloutPercentage >= 100) {
            return new FeatureFlagDecision(
                $key,
                true,
                FeatureFlagDecisionReason::FullRollout,
                bucket: 0,
                rolloutPercentage: 100,
            );
        }

        if ($definition->rolloutPercentage <= 0) {
            return new FeatureFlagDecision(
                $key,
                false,
                FeatureFlagDecisionReason::ZeroRollout,
                bucket: 0,
                rolloutPercentage: 0,
            );
        }

        $bucket = $this->bucket(
            $key,
            $definition->salt,
            $context->rolloutSubject(),
        );
        $enabled = $bucket < $definition->rolloutPercentage * 100;

        return new FeatureFlagDecision(
            $key,
            $enabled,
            $enabled
                ? FeatureFlagDecisionReason::PercentageRollout
                : FeatureFlagDecisionReason::OutsideRollout,
            bucket: $bucket,
            rolloutPercentage: $definition->rolloutPercentage,
        );
    }

    private function bucket(FeatureFlagKey $key, string $salt, string $subject): int
    {
        $hash = hash('sha256', $key->value . '|' . $salt . '|' . $subject);
        $prefix = substr($hash, 0, 8);

        return (int) (hexdec($prefix) % 10000);
    }
}
