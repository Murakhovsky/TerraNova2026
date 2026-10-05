<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Value;

use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class Price extends ValueObject
{
    public function __construct(
        public Decimal $value,
        public AssetCode $baseAsset,
        public AssetCode $quoteAsset,
        public int $precision,
    ) {
        if (!$this->value->isPositive()) throw new InvalidArgumentException('Price must be greater than zero.');
        if ($this->baseAsset->equals($this->quoteAsset)) throw new InvalidArgumentException('Price base and quote assets must differ.');
        if ($this->precision < 0 || $this->precision > 30) throw new InvalidArgumentException('Price precision must be between 0 and 30.');
        if ($this->value->scale() > $this->precision) throw new InvalidArgumentException('Price value exceeds declared precision.');
    }

    /** @return array{value:string,base_asset:string,quote_asset:string,precision:int} */
    public function toArray(): array
    {
        return ['value'=>$this->value->value(),'base_asset'=>$this->baseAsset->value(),'quote_asset'=>$this->quoteAsset->value(),'precision'=>$this->precision];
    }
}
