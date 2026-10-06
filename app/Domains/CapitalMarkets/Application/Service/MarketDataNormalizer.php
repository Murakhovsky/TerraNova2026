<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketDataNormalizerInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Application\DTO\ResolvedMarketInstrument;
use Domains\CapitalMarkets\Application\Exception\MarketDataNormalizationException;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketCandle;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketObservation;
use Domains\CapitalMarkets\Domain\MarketData\MarketOrderBook;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrade;
use Domains\CapitalMarkets\Domain\MarketData\MarketValueObservation;
use Domains\CapitalMarkets\Domain\MarketData\OrderBookLevel;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\TradeSide;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use InvalidArgumentException;
use Throwable;

final readonly class MarketDataNormalizer implements MarketDataNormalizerInterface
{
    public function __construct(
        private MarketInstrumentResolverInterface $resolver,
        private MarketClockInterface $clock,
    ){}

    public function normalize(
        string $organizationId,
        MarketSourceDescriptor $source,
        RawMarketEvent $raw,
        DecodedMarketEvent $decoded,
    ):CanonicalMarketEvent{
        $resolved=$this->resolver->resolve($organizationId,$source,$decoded->externalInstrument);
        if($resolved===null){
            throw new MarketDataNormalizationException('UNKNOWN_INSTRUMENT','Market-data symbol is not mapped to a canonical instrument.');
        }

        try{
            [$observation,$flags]=$this->observation($resolved,$decoded);
            return new CanonicalMarketEvent(
                $raw->eventId,
                $source->id,
                $source->venueId,
                $resolved->instrument->id,
                new MarketTimestamps($decoded->sourceTimestamp,$raw->receivedAt,$this->clock->now()),
                $decoded->sequence,
                $observation,
                array_values($flags),
                1,
                $decoded->mode,
                $decoded->marketStatus,
            );
        }catch(MarketDataNormalizationException $error){
            throw $error;
        }catch(Throwable $error){
            throw new MarketDataNormalizationException('INVALID_PROVIDER_VALUE',$error->getMessage());
        }
    }

    /** @return array{MarketObservation,list<MarketQualityFlag>} */
    private function observation(ResolvedMarketInstrument $resolved,DecodedMarketEvent $decoded):array
    {
        $flags=[];
        $base=new AssetCode((string)($decoded->values['base_asset']??$resolved->instrument->canonicalSymbol));
        $quote=$this->quoteAsset($resolved,$decoded);

        return match($decoded->eventType){
            MarketEventType::Quote,MarketEventType::Bbo=>[
                $this->quote($resolved,$decoded,$base,$quote,$flags),$flags,
            ],
            MarketEventType::Trade=>[
                $this->trade($resolved,$decoded,$base,$quote,$flags),$flags,
            ],
            MarketEventType::OrderBookSnapshot,MarketEventType::OrderBookDelta=>[
                $this->book($resolved,$decoded,$base,$quote,$flags),$flags,
            ],
            MarketEventType::Candle=>[
                $this->candle($resolved,$decoded,$base,$quote,$flags),$flags,
            ],
            MarketEventType::Volume,MarketEventType::ReferencePrice,MarketEventType::FundingRate,
            MarketEventType::OpenInterest,MarketEventType::MarkPrice,MarketEventType::IndexPrice=>[
                new MarketValueObservation($decoded->eventType,$this->decimal($decoded->values,'value'),$this->unit($decoded,$base,$quote)),$flags,
            ],
        };
    }

    /** @param list<MarketQualityFlag> $flags */
    private function quote(
        ResolvedMarketInstrument $resolved,DecodedMarketEvent $decoded,AssetCode $base,AssetCode $quote,array &$flags
    ):MarketQuote{
        $bid=$this->decimal($decoded->values,'bid_price');
        $ask=$this->decimal($decoded->values,'ask_price');
        $bidQty=$this->decimal($decoded->values,'bid_quantity');
        $askQty=$this->decimal($decoded->values,'ask_quantity');
        [$pricePrecision,$quantityPrecision]=$this->precisions($resolved->venueInstrument,[$bid,$ask],[$bidQty,$askQty],$flags);
        return new MarketQuote(
            new Price($bid,$base,$quote,$pricePrecision),
            new Quantity($bidQty,$base,$quantityPrecision),
            new Price($ask,$base,$quote,$pricePrecision),
            new Quantity($askQty,$base,$quantityPrecision),
            $decoded->eventType,
        );
    }

    /** @param list<MarketQualityFlag> $flags */
    private function trade(
        ResolvedMarketInstrument $resolved,DecodedMarketEvent $decoded,AssetCode $base,AssetCode $quote,array &$flags
    ):MarketTrade{
        $price=$this->decimal($decoded->values,'price');
        $quantity=$this->decimal($decoded->values,'quantity');
        [$pricePrecision,$quantityPrecision]=$this->precisions($resolved->venueInstrument,[$price],[$quantity],$flags);
        $side=null;
        $sideValue=$decoded->values['side']??null;
        if(is_string($sideValue)&&trim($sideValue)!==''){
            $normalized=strtoupper(trim($sideValue));
            if(in_array($normalized,['BUY','SELL'],true))$side=TradeSide::from($normalized);
        }
        return new MarketTrade(
            $this->string($decoded->values,'trade_id'),
            new Price($price,$base,$quote,$pricePrecision),
            new Quantity($quantity,$base,$quantityPrecision),
            $side,
        );
    }

    /** @param list<MarketQualityFlag> $flags */
    private function book(
        ResolvedMarketInstrument $resolved,DecodedMarketEvent $decoded,AssetCode $base,AssetCode $quote,array &$flags
    ):MarketOrderBook{
        $bids=$this->levels($decoded->values['bids']??null,$base,$quote,$resolved->venueInstrument,$flags);
        $asks=$this->levels($decoded->values['asks']??null,$base,$quote,$resolved->venueInstrument,$flags);
        return new MarketOrderBook($decoded->eventType,$decoded->sequence,$bids,$asks);
    }

    /** @param list<MarketQualityFlag> $flags */
    private function candle(
        ResolvedMarketInstrument $resolved,DecodedMarketEvent $decoded,AssetCode $base,AssetCode $quote,array &$flags
    ):MarketCandle{
        $open=$this->decimal($decoded->values,'open');
        $high=$this->decimal($decoded->values,'high');
        $low=$this->decimal($decoded->values,'low');
        $close=$this->decimal($decoded->values,'close');
        $volume=$this->decimal($decoded->values,'volume');
        [$pricePrecision]=$this->precisions($resolved->venueInstrument,[$open,$high,$low,$close],[$volume],$flags);
        return new MarketCandle(
            new DateTimeImmutable($this->string($decoded->values,'open_time')),
            new DateTimeImmutable($this->string($decoded->values,'close_time')),
            new Price($open,$base,$quote,$pricePrecision),
            new Price($high,$base,$quote,$pricePrecision),
            new Price($low,$base,$quote,$pricePrecision),
            new Price($close,$base,$quote,$pricePrecision),
            $volume,
            (string)($decoded->values['source_type']??'PROVIDER'),
        );
    }

    /** @param list<MarketQualityFlag> $flags @return list<OrderBookLevel> */
    private function levels(mixed $value,AssetCode $base,AssetCode $quote,?VenueInstrument $mapping,array &$flags):array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Order-book levels must be a list.');
        $out=[];
        foreach($value as $row){
            if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('Order-book level must be an object.');
            $price=$this->decimal($row,'price');
            $quantity=$this->decimal($row,'quantity');
            [$pricePrecision,$quantityPrecision]=$this->precisions($mapping,[$price],[$quantity],$flags);
            $orderCount=$row['order_count']??null;
            if($orderCount!==null&&!is_int($orderCount))throw new InvalidArgumentException('Order-book order_count must be integer or null.');
            $out[]=new OrderBookLevel(
                new Price($price,$base,$quote,$pricePrecision),
                new Quantity($quantity,$base,$quantityPrecision),
                $orderCount,
            );
        }
        return $out;
    }

    /** @param list<Decimal> $prices @param list<Decimal> $quantities @param list<MarketQualityFlag> $flags @return array{int,int} */
    private function precisions(?VenueInstrument $mapping,array $prices,array $quantities,array &$flags):array
    {
        $providerPrice=max(array_map(static fn(Decimal $value):int=>$value->scale(),$prices));
        $providerQuantity=max(array_map(static fn(Decimal $value):int=>$value->scale(),$quantities));
        if($mapping!==null&&($providerPrice>$mapping->pricePrecision||$providerQuantity>$mapping->quantityPrecision)){
            $flags[MarketQualityFlag::PrecisionMismatch->value]=MarketQualityFlag::PrecisionMismatch;
        }
        return [max($providerPrice,$mapping?->pricePrecision??0),max($providerQuantity,$mapping?->quantityPrecision??0)];
    }

    private function quoteAsset(ResolvedMarketInstrument $resolved,DecodedMarketEvent $decoded):AssetCode
    {
        $explicit=$decoded->values['quote_asset']??null;
        if(is_string($explicit)&&trim($explicit)!=='')return new AssetCode($explicit);
        if($resolved->instrument->quoteAsset!==null)return $resolved->instrument->quoteAsset;
        if($resolved->instrument->currency!==null)return new AssetCode($resolved->instrument->currency->value());
        throw new MarketDataNormalizationException('MISSING_QUOTE_ASSET','Canonical instrument has no quote asset/currency.');
    }

    private function unit(DecodedMarketEvent $decoded,AssetCode $base,AssetCode $quote):?AssetCode
    {
        $unit=$decoded->values['unit']??null;
        if(is_string($unit)&&trim($unit)!=='')return new AssetCode($unit);
        return match($decoded->eventType){
            MarketEventType::Volume,MarketEventType::OpenInterest=>$base,
            MarketEventType::ReferencePrice,MarketEventType::MarkPrice,MarketEventType::IndexPrice=>$quote,
            default=>null,
        };
    }

    /** @param array<string,mixed> $data */
    private function decimal(array $data,string $key):Decimal
    {
        $value=$data[$key]??null;
        if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException($key.' must be an explicit decimal string.');
        return Decimal::fromString((string)$value);
    }

    /** @param array<string,mixed> $data */
    private function string(array $data,string $key):string
    {
        $value=$data[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException($key.' must be a non-empty string.');
        return trim($value);
    }
}
