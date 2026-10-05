<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class MarketValueObservation extends ValueObject implements MarketObservation
{
    public function __construct(
        private MarketEventType $type,
        public Decimal $value,
        public ?AssetCode $unit=null,
    ){
        if(!in_array($this->type,[
            MarketEventType::Volume,
            MarketEventType::ReferencePrice,
            MarketEventType::FundingRate,
            MarketEventType::OpenInterest,
            MarketEventType::MarkPrice,
            MarketEventType::IndexPrice,
        ],true)){
            throw new InvalidArgumentException('Scalar market observation type is unsupported.');
        }
    }

    public function eventType():MarketEventType{return $this->type;}

    public function toArray():array
    {
        return ['value'=>$this->value->value(),'unit'=>$this->unit?->value()];
    }
}
