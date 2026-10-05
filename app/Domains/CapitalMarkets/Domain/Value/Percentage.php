<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use Kernel\Shared\Domain\ValueObject;

final readonly class Percentage extends ValueObject
{
    public const REPRESENTATION = 'decimal_fraction';

    public function __construct(public Decimal $ratio) {}

    /** @return array{ratio:string,representation:string} */
    public function toArray(): array
    {
        return ['ratio'=>$this->ratio->value(),'representation'=>self::REPRESENTATION];
    }
}
