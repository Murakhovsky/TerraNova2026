<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class FeatureFlagDefinition
{
    public function __construct(
        public FeatureFlagKey $key,
        public bool $enabled,
        public int $rolloutPercentage,
        public string $salt,
        public string $description = '',
        public ?DateTimeImmutable $startsAt = null,
        public ?DateTimeImmutable $endsAt = null,
    ) {
        if ($this->rolloutPercentage < 0 || $this->rolloutPercentage > 100) {
            throw new InvalidArgumentException('Feature flag rollout percentage must be between 0 and 100.');
        }

        if ($this->salt === '' || trim($this->salt) !== $this->salt) {
            throw new InvalidArgumentException('Feature flag rollout salt must be non-empty.');
        }

        if ($this->startsAt !== null && $this->endsAt !== null && $this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('Feature flag end time must be later than start time.');
        }
    }

    public function isScheduledAt(DateTimeImmutable $at): bool
    {
        if ($this->startsAt !== null && $at < $this->startsAt) {
            return false;
        }

        if ($this->endsAt !== null && $at >= $this->endsAt) {
            return false;
        }

        return true;
    }
}
