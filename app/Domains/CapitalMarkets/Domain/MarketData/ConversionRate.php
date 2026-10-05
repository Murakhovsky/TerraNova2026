<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;

final readonly class ConversionRate
{
    public function __construct(
        public AssetCode $sourceAsset,
        public AssetCode $targetAsset,
        public Decimal $rate,
        public DateTimeImmutable $timestamp,
        public string $source,
        public MarketQualityStatus $quality,
    ){
        if($this->sourceAsset->equals($this->targetAsset))throw new InvalidArgumentException('Conversion assets must differ.');
        if(!$this->rate->isPositive())throw new InvalidArgumentException('Conversion rate must be positive.');
        if(trim($this->source)==='')throw new InvalidArgumentException('Conversion source is required.');
    }

    public function toArray():array{return [
        'source_asset'=>$this->sourceAsset->value(),'target_asset'=>$this->targetAsset->value(),
        'rate'=>$this->rate->value(),'timestamp'=>$this->timestamp->format(DATE_ATOM),
        'source'=>$this->source,'quality'=>$this->quality->value,
    ];}
}
