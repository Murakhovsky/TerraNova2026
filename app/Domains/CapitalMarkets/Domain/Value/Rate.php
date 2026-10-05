<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use Kernel\Shared\Domain\ValueObject;

final readonly class Rate extends ValueObject
{
    public const REPRESENTATION = 'decimal_fraction';

    public function __construct(
        public Decimal $ratio,
        public RateKind $kind = RateKind::Interest,
    ) {}

    /** @return array{ratio:string,kind:string,representation:string} */
    public function toArray(): array
    {
        return ['ratio'=>$this->ratio->value(),'kind'=>$this->kind->value,'representation'=>self::REPRESENTATION];
    }
}
