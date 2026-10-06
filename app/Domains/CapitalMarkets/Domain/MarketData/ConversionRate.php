<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class ConversionRate extends ValueObject
{
    public function __construct(
        public AssetCode $sourceAsset,
        public AssetCode $targetAsset,
        public Decimal $rate,
        public DateTimeImmutable $timestamp,
        public MarketSourceId $sourceId,
        public MarketTrustStatus $quality,
    ){
        if($this->sourceAsset->equals($this->targetAsset))throw new InvalidArgumentException('Conversion rate assets must differ.');
        if(!$this->rate->isPositive())throw new InvalidArgumentException('Conversion rate must be positive.');
    }

    public function usable():bool{return $this->quality->isUsableForDecision();}

    /** @return array<string,mixed> */
    public function toArray():array
    {
        return [
            'source_asset'=>$this->sourceAsset->value(),
            'target_asset'=>$this->targetAsset->value(),
            'rate'=>$this->rate->value(),
            'timestamp'=>$this->timestamp->format(DATE_ATOM),
            'source'=>$this->sourceId->value(),
            'quality'=>$this->quality->value,
        ];
    }
}
