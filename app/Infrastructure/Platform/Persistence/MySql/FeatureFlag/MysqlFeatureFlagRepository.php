<?php

declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql\FeatureFlag;

use DateTimeImmutable;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use Platform\FeatureFlag\Contract\FeatureFlagRepositoryInterface;
use Platform\FeatureFlag\Model\FeatureFlagDefinition;
use Platform\FeatureFlag\Model\FeatureFlagKey;
use Platform\FeatureFlag\Model\FeatureFlagOverride;
use Platform\FeatureFlag\Model\FeatureFlagOverrideScope;

final readonly class MysqlFeatureFlagRepository implements FeatureFlagRepositoryInterface
{
    public function __construct(
        private PdoConnection $database,
    ) {
    }

    public function definition(FeatureFlagKey $key): ?FeatureFlagDefinition
    {
        $row = $this->database->fetchOne(
            'SELECT flag_key, description, enabled, rollout_percentage, rollout_salt, starts_at, ends_at
             FROM cos_feature_flags
             WHERE flag_key = :flag_key
             LIMIT 1',
            ['flag_key' => $key->value],
        );

        if ($row === null) {
            return null;
        }

        return new FeatureFlagDefinition(
            key: new FeatureFlagKey((string) $row['flag_key']),
            enabled: (bool) $row['enabled'],
            rolloutPercentage: (int) $row['rollout_percentage'],
            salt: (string) $row['rollout_salt'],
            description: (string) ($row['description'] ?? ''),
            startsAt: $this->date($row['starts_at'] ?? null),
            endsAt: $this->date($row['ends_at'] ?? null),
        );
    }

    public function override(
        FeatureFlagKey $key,
        string $organizationId,
        ?string $userId,
        DateTimeImmutable $at,
    ): ?FeatureFlagOverride {
        if ($userId !== null) {
            $user = $this->overrideFor(
                $key,
                $organizationId,
                FeatureFlagOverrideScope::User,
                $userId,
                $at,
            );

            if ($user !== null) {
                return $user;
            }
        }

        return $this->overrideFor(
            $key,
            $organizationId,
            FeatureFlagOverrideScope::Organization,
            $organizationId,
            $at,
        );
    }

    private function overrideFor(
        FeatureFlagKey $key,
        string $organizationId,
        FeatureFlagOverrideScope $scope,
        string $subjectId,
        DateTimeImmutable $at,
    ): ?FeatureFlagOverride {
        $row = $this->database->fetchOne(
            'SELECT subject_type, subject_id, enabled, expires_at, reason
             FROM cos_feature_flag_overrides
             WHERE flag_key = :flag_key
               AND organization_id = :organization_id
               AND subject_type = :subject_type
               AND subject_id = :subject_id
               AND (expires_at IS NULL OR expires_at > :at)
             LIMIT 1',
            [
                'flag_key' => $key->value,
                'organization_id' => $organizationId,
                'subject_type' => $scope->value,
                'subject_id' => $subjectId,
                'at' => $at->format('Y-m-d H:i:s.u'),
            ],
        );

        if ($row === null) {
            return null;
        }

        return new FeatureFlagOverride(
            scope: FeatureFlagOverrideScope::from((string) $row['subject_type']),
            subjectId: (string) $row['subject_id'],
            enabled: (bool) $row['enabled'],
            expiresAt: $this->date($row['expires_at'] ?? null),
            reason: (string) ($row['reason'] ?? ''),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return new DateTimeImmutable((string) $value);
    }
}
