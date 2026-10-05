<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use Kernel\Shared\Domain\ValueObject;

final readonly class Rate extends ValueObject
{
    public function __construct(public Decimal $ratio)
    {
    }
}
