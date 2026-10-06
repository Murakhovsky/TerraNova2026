<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Instrument;

use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class PerpetualProfile extends ValueObject
{
    /** @param list<MarginMode> $marginModes @param array<string,mixed> $venueMetadata */
    public function __construct(
        public AssetCode $underlying,
        public ContractType $contractType,
        public AssetCode $settlementAsset,
        public AssetCode $marginAsset,
        public Decimal $contractSize,
        public Decimal $contractMultiplier,
        public int $pricePrecision,
        public int $quantityPrecision,
        public Decimal $minimumQuantity,
        public Decimal $minimumNotional,
        public Decimal $maximumLeverage,
        public array $marginModes,
        public bool $fundingSupported,
        public ?int $fundingIntervalSeconds,
        public string $markPriceSource,
        public string $indexPriceSource,
        public ?string $liquidationModelReference,
        public array $venueMetadata=[],
    ){
        foreach([$contractSize,$contractMultiplier,$minimumQuantity,$maximumLeverage] as $value){
            if(!$value->isPositive())throw new InvalidArgumentException('Perpetual positive numeric fields must be > 0.');
        }
        if($minimumNotional->isNegative())throw new InvalidArgumentException('Perpetual minimum notional cannot be negative.');
        if($pricePrecision<0||$pricePrecision>30||$quantityPrecision<0||$quantityPrecision>30){
            throw new InvalidArgumentException('Perpetual precision must be between 0 and 30.');
        }
        if($fundingSupported&&($fundingIntervalSeconds===null||$fundingIntervalSeconds<60)){
            throw new InvalidArgumentException('Funding-enabled perpetual requires a venue-derived funding interval.');
        }
        foreach($marginModes as $mode)if(!$mode instanceof MarginMode)throw new InvalidArgumentException('Margin modes must be typed.');
        if($markPriceSource===''||$indexPriceSource==='')throw new InvalidArgumentException('Perpetual mark/index price sources are required.');
        InstrumentDescriptor::assertMetadata($venueMetadata);
    }

    public function v1Executable():bool
    {
        return $this->contractType===ContractType::Linear
            && $this->settlementAsset->equals($this->marginAsset);
    }
}
