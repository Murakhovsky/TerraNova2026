<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DateTimeImmutable;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;
use Kernel\Shared\Domain\ValueObject;

final readonly class BasisObservation extends ValueObject
{
    public function __construct(
        public string $spotMarket,
        public string $perpetualMarket,
        public DateTimeImmutable $timestamp,
        public Decimal $spotBid,
        public Decimal $spotAsk,
        public Decimal $spotMid,
        public Decimal $perpBid,
        public Decimal $perpAsk,
        public Decimal $perpMid,
        public ?Decimal $markPrice,
        public ?Decimal $indexPrice,
        public Decimal $midBasisAbsolute,
        public Decimal $midBasisBps,
        public Decimal $longSpotShortPerpExecutableBasis,
        public Decimal $shortSpotLongPerpExecutableBasis,
        public int $quality,
    ){
        if($spotMarket===''||$perpetualMarket==='')throw new InvalidArgumentException('Basis markets are required.');
        if($quality<0||$quality>100)throw new InvalidArgumentException('Basis quality must be between 0 and 100.');
    }

    public static function fromTopOfBook(
        string $spotMarket,string $perpetualMarket,DateTimeImmutable $timestamp,
        Decimal $spotBid,Decimal $spotAsk,Decimal $perpBid,Decimal $perpAsk,
        ?Decimal $markPrice=null,?Decimal $indexPrice=null,int $quality=100,
    ):self{
        $spotMid=DecimalMath::midpoint($spotBid,$spotAsk);
        $perpMid=DecimalMath::midpoint($perpBid,$perpAsk);
        $mid=DecimalMath::subtract($perpMid,$spotMid);
        return new self(
            $spotMarket,$perpetualMarket,$timestamp,
            $spotBid,$spotAsk,$spotMid,$perpBid,$perpAsk,$perpMid,$markPrice,$indexPrice,
            $mid,DecimalMath::basisPoints($mid,$spotMid,8),
            DecimalMath::subtract($perpBid,$spotAsk),
            DecimalMath::subtract($spotBid,$perpAsk),
            $quality,
        );
    }
}
