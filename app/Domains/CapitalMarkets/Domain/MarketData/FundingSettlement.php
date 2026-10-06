<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Portfolio\PositionSide;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class FundingSettlement extends ValueObject
{
    public function __construct(
        public VenueId $venue,
        public InstrumentId $instrument,
        public string $positionReference,
        public DateTimeImmutable $settlementAt,
        public Decimal $rate,
        public Decimal $positionNotional,
        public PositionSide $side,
        public Decimal $grossCashflow,
        public AssetCode $currency,
        public string $source,
    ){
        if($positionReference===''||$source==='')throw new InvalidArgumentException('Funding settlement identity/source are required.');
        if($positionNotional->isNegative())throw new InvalidArgumentException('Funding settlement notional cannot be negative.');
    }

    public function received():bool{return $this->grossCashflow->isPositive();}
    public function paid():bool{return $this->grossCashflow->isNegative();}
}
