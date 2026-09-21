<?php

declare(strict_types=1);

namespace Platform\FeatureFlag\Model;

use InvalidArgumentException;

final readonly class FeatureFlagKey
{
    public function __construct(
        public string $value,
    ) {
        if (!preg_match('/^[a-z][a-z0-9._-]{2,119}$/', $this->value)) {
            throw new InvalidArgumentException('Feature flag key must be a stable lowercase identifier.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
