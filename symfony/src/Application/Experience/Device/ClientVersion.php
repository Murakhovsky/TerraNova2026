<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

use InvalidArgumentException;

final readonly class ClientVersion
{
    public function __construct(
        public string $value,
    ) {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $this->value)) {
            throw new InvalidArgumentException('ClientVersion must use semantic version format.');
        }
    }

    public function compareTo(self $other): int
    {
        return version_compare($this->value, $other->value);
    }

    public function isAtLeast(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }
}
