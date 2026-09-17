<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

abstract class Entity
{
    abstract public function id(): string;

    final public function sameIdentityAs(self $other): bool
    {
        return $this::class === $other::class && $this->id() === $other->id();
    }
}
