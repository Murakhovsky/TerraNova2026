<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\DecimalMath;
use InvalidArgumentException;

final readonly class OkxPerpetualMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(private OkxPublicPayloadParser $parser){}
    public function adapterType():string{return OkxPerpetualMarketDataAdapter::ADAPTER_TYPE;}

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType())throw new InvalidArgumentException('OKX decoder received another adapter type.');
        $status=match((string)($event->transportMetadata['market_status']??'UNKNOWN')){
            'OPEN'=>MarketStatus::Open,'CLOSED'=>MarketStatus::Closed,default=>MarketStatus::Unknown,
        };
        $at=$event->providerTimestamp??$event->receivedAt;
        return match($event->eventType){
            'okx.swap.ticker.bbo'=>$this->bbo($event,$at,$status),
            'okx.swap.ticker.volume'=>$this->volume($event,$at,$status),
            'okx.swap.funding'=>$this->funding($event,$at,$status),
            'okx.swap.mark_price'=>$this->mark($event,$at,$status),
            'okx.swap.index_price'=>$this->index($event,$at,$status),
            'okx.swap.open_interest'=>$this->oi($event,$at,$status),
            'okx.swap.orderbook.snapshot'=>$this->book($event,$at,$status),
            'okx.swap.instrument.metadata'=>$this->metadata($event,$at,$status),
            default=>throw new InvalidArgumentException('Unsupported OKX swap event type: '.$event->eventType),
        };
    }

    private function bbo(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $row=$this->ticker($event);$factor=$this->underlyingPerContract($event);
        return $this->decoded(MarketEventType::Bbo,$event,$at,$status,[
            'bid_price'=>$this->parser->decimal($row['bidPx']??null,'bidPx',true),
            'bid_quantity'=>$this->contractsToUnderlying($row['bidSz']??null,$factor),
            'ask_price'=>$this->parser->decimal($row['askPx']??null,'askPx',true),
            'ask_quantity'=>$this->contractsToUnderlying($row['askSz']??null,$factor),
        ]);
    }

    private function volume(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $row=$this->ticker($event);
        return $this->decoded(MarketEventType::Volume,$event,$at,$status,['value'=>$this->contractsToUnderlying($row['vol24h']??null,$this->underlyingPerContract($event))]);
    }

    private function funding(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['funding_json']??null;
        if(!is_string($body))throw new InvalidArgumentException('OKX funding payload is missing.');
        $row=$this->parser->funding($body,$event->externalInstrument);
        $fundingTime=(int)$this->parser->unsigned($row['fundingTime']??null,'fundingTime');
        $next=(int)$this->parser->unsigned($row['nextFundingTime']??null,'nextFundingTime');
        $interval=intdiv(max(0,$next-$fundingTime),1000);
        if($interval<60)throw new InvalidArgumentException('OKX funding interval is invalid.');
        return $this->decoded(MarketEventType::FundingRate,$event,$at,$status,[
            'value'=>$this->parser->decimal($row['fundingRate']??null,'fundingRate'),
            'rate_type'=>'NORMALIZED_LONGS_PAY_SHORTS','status'=>'CURRENT',
            'next_settlement_at'=>(string)$next,'funding_interval_seconds'=>$interval,
            'cap'=>(string)($row['maxFundingRate']??''),'floor'=>(string)($row['minFundingRate']??''),
            'formula_type'=>(string)($row['formulaType']??''),'settlement_state'=>(string)($row['settState']??''),
        ]);
    }

    private function mark(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['mark_json']??null;if(!is_string($body))throw new InvalidArgumentException('OKX mark payload missing.');
        $row=$this->parser->mark($body,$event->externalInstrument);
        return $this->decoded(MarketEventType::MarkPrice,$event,$at,$status,['value'=>$this->parser->decimal($row['markPx']??null,'markPx',true)]);
    }

    private function index(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['index_json']??null;$indexId=$event->rawPayload['index_id']??null;
        if(!is_string($body)||!is_string($indexId))throw new InvalidArgumentException('OKX index payload missing.');
        $row=$this->parser->index($body,$indexId);
        return $this->decoded(MarketEventType::IndexPrice,$event,$at,$status,['value'=>$this->parser->decimal($row['idxPx']??null,'idxPx',true),'index_id'=>$indexId]);
    }

    private function oi(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['oi_json']??null;if(!is_string($body))throw new InvalidArgumentException('OKX open-interest payload missing.');
        $row=$this->parser->openInterest($body,$event->externalInstrument);
        return $this->decoded(MarketEventType::OpenInterest,$event,$at,$status,['value'=>$this->contractsToUnderlying($row['oi']??null,$this->underlyingPerContract($event))]);
    }

    private function book(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['book_json']??null;if(!is_string($body))throw new InvalidArgumentException('OKX book payload missing.');
        $book=$this->parser->book($body);$factor=$this->underlyingPerContract($event);
        $convert=fn(array $levels):array=>array_map(fn(array $x):array=>[
            'price'=>$x['price'],'quantity'=>$this->contractsToUnderlying($x['quantity_contracts'],$factor),'order_count'=>$x['order_count'],
        ],$levels);
        return $this->decoded(MarketEventType::OrderBookSnapshot,$event,$at,$status,['bids'=>$convert($book['bids']),'asks'=>$convert($book['asks'])]);
    }

    private function metadata(RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status):DecodedMarketEvent
    {
        $row=$this->instrument($event);$factor=$this->underlyingPerContract($event);
        $tick=$this->parser->decimal($row['tickSz']??null,'tickSz',true);
        $lot=$this->parser->decimal($row['lotSz']??null,'lotSz',true);
        return $this->decoded(MarketEventType::InstrumentMetadata,$event,$at,$status,[
            'base_asset'=>(string)($row['ctValCcy']??''),'quote_asset'=>(string)($row['settleCcy']??''),
            'contract_type'=>strtoupper((string)($row['ctType']??'OTHER')),'settlement_asset'=>(string)($row['settleCcy']??''),
            'margin_asset'=>(string)($row['settleCcy']??''),'contract_size'=>(string)($row['ctVal']??''),
            'contract_multiplier'=>(string)(($row['ctMult']??'')===''?'1':$row['ctMult']),
            'price_precision'=>$this->parser->precision($tick),'quantity_precision'=>$this->parser->precision($lot),
            'minimum_quantity'=>$this->contractsToUnderlying($row['minSz']??null,$factor),'maximum_leverage'=>(string)($row['lever']??''),
            'underlying_index'=>(string)($row['uly']??''),'venue_status'=>(string)($row['state']??''),
            'mark_price_source'=>'OKX_MARK_PRICE','index_price_source'=>'OKX_INDEX_PRICE','liquidation_model_reference'=>'OKX_V5_SWAP',
        ]);
    }

    /** @return array<string,mixed> */
    private function ticker(RawMarketEvent $event):array
    {
        $body=$event->rawPayload['ticker_json']??null;if(!is_string($body))throw new InvalidArgumentException('OKX ticker payload missing.');
        return $this->parser->ticker($body,$event->externalInstrument);
    }
    /** @return array<string,mixed> */
    private function instrument(RawMarketEvent $event):array
    {
        $body=$event->rawPayload['instrument_json']??null;if(!is_string($body))throw new InvalidArgumentException('OKX instrument payload missing.');
        return $this->parser->instrument($body,$event->externalInstrument);
    }
    private function underlyingPerContract(RawMarketEvent $event):Decimal
    {
        $row=$this->instrument($event);
        if(strtolower((string)($row['ctType']??''))!=='linear')throw new InvalidArgumentException('VS2 V1 supports only OKX linear swaps.');
        $val=Decimal::fromString($this->parser->decimal($row['ctVal']??null,'ctVal',true));
        $multRaw=(string)($row['ctMult']??'');$mult=Decimal::fromString($multRaw===''?'1':$this->parser->decimal($multRaw,'ctMult',true));
        return DecimalMath::multiply($val,$mult);
    }
    private function contractsToUnderlying(mixed $contracts,Decimal $factor):string
    {
        return DecimalMath::multiply(Decimal::fromString($this->parser->decimal($contracts,'contract quantity')),$factor)->value();
    }

    /** @param array<string,mixed> $values */
    private function decoded(MarketEventType $type,RawMarketEvent $event,DateTimeImmutable $at,MarketStatus $status,array $values):DecodedMarketEvent
    {
        return new DecodedMarketEvent($type,$event->externalInstrument,$at,$event->sequence,$values,$event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference);
    }
}
