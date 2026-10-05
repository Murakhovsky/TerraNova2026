<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Quantity extends ValueObject
{
    public function __construct(
        public Decimal $value,
        public AssetCode $asset,
        public int $precision,
    ) {
        if ($this->value->isNegative()) throw new InvalidArgumentException('Quantity cannot be negative.');
        if ($this->precision < 0 || $this->precision > 30) throw new InvalidArgumentException('Quantity precision must be between 0 and 30.');
        if ($this->value->scale() > $this->precision) throw new InvalidArgumentException('Quantity value exceeds declared precision.');
    }

    /** @return array{value:string,asset:string,precision:int} */
    public function toArray(): array
    {
        return ['value'=>$this->value->value(),'asset'=>$this->asset->value(),'precision'=>$this->precision];
    }
}
