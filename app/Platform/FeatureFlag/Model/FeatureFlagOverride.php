<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FeatureFlagOverride
{
    public function __construct(
        public FeatureFlagOverrideScope $scope,
        public string $subjectId,
        public bool $enabled,
        public ?DateTimeImmutable $expiresAt = null,
        public string $reason = '',
    ) {
        if ($this->subjectId === '' || trim($this->subjectId) !== $this->subjectId) {
            throw new InvalidArgumentException('Feature flag override subject must be non-empty.');
        }
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        return $this->expiresAt === null || $this->expiresAt > $at;
    }
}
