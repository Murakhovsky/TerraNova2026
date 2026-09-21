<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

use InvalidArgumentException;

final readonly class FeatureFlagContext
{
    public function __construct(
        public string $organizationId,
        public ?string $userId = null,
        public string $surface = 'server',
    ) {
        if (preg_match('/^[A-Za-z0-9._:-]{1,190}$/', $this->organizationId) !== 1) {
            throw new InvalidArgumentException('Feature flag context requires a canonical organization id.');
        }

        if ($this->userId !== null && ($this->userId === '' || trim($this->userId) !== $this->userId)) {
            throw new InvalidArgumentException('Feature flag user id must be null or a canonical identifier.');
        }

        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $this->surface) !== 1) {
            throw new InvalidArgumentException('Feature flag surface must be a stable lowercase identifier.');
        }
    }

    public function rolloutSubject(): string
    {
        return $this->organizationId . ':' . ($this->userId ?? 'organization');
    }
}
