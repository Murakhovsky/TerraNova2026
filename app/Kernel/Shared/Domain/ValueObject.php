<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

abstract readonly class ValueObject
{
    final public function equals(self $other): bool
    {
        return $this::class === $other::class && $this == $other;
    }
}
