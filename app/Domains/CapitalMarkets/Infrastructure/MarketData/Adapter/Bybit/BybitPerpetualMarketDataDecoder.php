<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use InvalidArgumentException;

final readonly class BybitPerpetualMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(
        private BybitPerpetualTickerPayloadParser $ticker,
        private BybitPerpetualInstrumentPayloadParser $instrument,
        private BybitOrderBookPayloadParser $orderBooks,
    ){}

    public function adapterType():string{return BybitPerpetualMarketDataAdapter::ADAPTER_TYPE;}

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType())throw new InvalidArgumentException('Bybit perpetual decoder received another adapter type.');
        $timestamp=$event->providerTimestamp??$event->receivedAt;
        $status=match((string)($event->transportMetadata['market_status']??'UNKNOWN')){
            'OPEN'=>MarketStatus::Open,'CLOSED'=>MarketStatus::Closed,default=>MarketStatus::Unknown,
        };
        return match($event->eventType){
            'bybit.linear.ticker.bbo'=>$this->bbo($event,$timestamp,$status),
            'bybit.linear.ws.topbook.bbo'=>$this->wsTopBook($event,$timestamp,$status),
            'bybit.linear.ticker.volume'=>$this->scalar($event,$timestamp,$status,MarketEventType::Volume,'volume24h'),
            'bybit.linear.ws.derivative.mark_price'=>$this->wsScalar($event,$timestamp,$status,MarketEventType::MarkPrice),
            'bybit.linear.ws.derivative.index_price'=>$this->wsScalar($event,$timestamp,$status,MarketEventType::IndexPrice),
            'bybit.linear.ws.derivative.open_interest'=>$this->wsScalar($event,$timestamp,$status,MarketEventType::OpenInterest),
            'bybit.linear.ws.derivative.funding'=>$this->wsFunding($event,$timestamp,$status),
            'bybit.linear.ticker.mark_price'=>$this->scalar($event,$timestamp,$status,MarketEventType::MarkPrice,'markPrice'),
            'bybit.linear.ticker.index_price'=>$this->scalar($event,$timestamp,$status,MarketEventType::IndexPrice,'indexPrice'),
            'bybit.linear.ticker.open_interest'=>$this->scalar($event,$timestamp,$status,MarketEventType::OpenInterest,'openInterest'),
            'bybit.linear.ticker.funding'=>$this->funding($event,$timestamp,$status),
            'bybit.linear.instrument.metadata'=>$this->metadata($event,$timestamp,$status),
            'bybit.linear.orderbook.snapshot'=>$this->book($event,$timestamp,$status),
            default=>throw new InvalidArgumentException('Unsupported Bybit perpetual event type: '.$event->eventType),
        };
    }

    private function bbo(RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $row=$this->tickerRow($event);
        return $this->decoded(MarketEventType::Bbo,$event,$timestamp,$status,[
            'bid_price'=>$row['bid1Price'],'bid_quantity'=>$row['bid1Size'],
            'ask_price'=>$row['ask1Price'],'ask_quantity'=>$row['ask1Size'],
        ]);
    }

    /**
     * WebSocket orderbook.1 is a complete top-of-book snapshot, never L2
     * continuity evidence and never a REST derivatives ticker structure.
     */
    private function wsTopBook(
        RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status,
    ):DecodedMarketEvent{
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit linear BBO snapshot missing.');
        $book=$this->orderBooks->parse($body,$event->externalInstrument);
        if(count($book['bids'])!==1||count($book['asks'])!==1){
            throw new InvalidArgumentException('Bybit linear BBO snapshot requires exactly one best bid and ask.');
        }
        return $this->decoded(MarketEventType::Bbo,$event,$timestamp,$status,[
            'bid_price'=>$book['bids'][0]['price'],
            'bid_quantity'=>$book['bids'][0]['quantity'],
            'ask_price'=>$book['asks'][0]['price'],
            'ask_quantity'=>$book['asks'][0]['quantity'],
        ]);
    }

    private function scalar(RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status,MarketEventType $type,string $field):DecodedMarketEvent
    {
        $row=$this->tickerRow($event);
        return $this->decoded($type,$event,$timestamp,$status,['value'=>$row[$field]]);
    }

    private function wsScalar(RawMarketEvent $event,DateTimeImmutable $time,
        MarketStatus $status,MarketEventType $type):DecodedMarketEvent
    {
        $v=$event->rawPayload['value']??null;
        if(!is_string($v)||preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$v)!==1){
            throw new InvalidArgumentException('Invalid derivative WS scalar.');
        }
        return $this->decoded($type,$event,$time,$status,['value'=>$v]);
    }

    private function wsFunding(RawMarketEvent $event,DateTimeImmutable $time,
        MarketStatus $status):DecodedMarketEvent
    {
        $v=$event->rawPayload;
        foreach(['value','next_settlement_at','funding_interval_seconds','rate_type','status'] as $key){
            if(!array_key_exists($key,$v))throw new InvalidArgumentException('Incomplete WS funding evidence.');
        }
        if(!is_int($v['funding_interval_seconds'])||$v['funding_interval_seconds']<60
            ||!is_string($v['next_settlement_at'])||!ctype_digit($v['next_settlement_at'])
            ||$v['rate_type']!=='NORMALIZED_LONGS_PAY_SHORTS'||$v['status']!=='CURRENT'){
            throw new InvalidArgumentException('Invalid derivative funding time/normalization.');
        }
        return $this->decoded(MarketEventType::FundingRate,$event,$time,$status,$v);
    }

    private function funding(RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $ticker=$this->tickerRow($event);
        $body=$event->rawPayload['instrument_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit funding instrument metadata is missing.');
        $instrument=$this->instrument->parse($body,$event->externalInstrument);
        return $this->decoded(MarketEventType::FundingRate,$event,$timestamp,$status,[
            'value'=>$ticker['fundingRate'],
            'rate_type'=>'NORMALIZED_LONGS_PAY_SHORTS',
            'status'=>'CURRENT',
            'next_settlement_at'=>$ticker['nextFundingTime'],
            'funding_interval_seconds'=>$instrument['funding_interval_minutes']*60,
            'cap'=>$instrument['upper_funding_rate'],
            'floor'=>$instrument['lower_funding_rate'],
        ]);
    }

    private function metadata(RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['instrument_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit instrument metadata payload is missing.');
        $row=$this->instrument->parse($body,$event->externalInstrument);
        return $this->decoded(MarketEventType::InstrumentMetadata,$event,$timestamp,$status,[
            'base_asset'=>$row['base_coin'],'quote_asset'=>$row['quote_coin'],
            'contract_type'=>$row['contract_type'],'settlement_asset'=>$row['settle_coin'],
            'margin_asset'=>$row['settle_coin'],'contract_size'=>'1','contract_multiplier'=>'1',
            'price_precision'=>$this->instrument->precision($row['tick_size']),
            'quantity_precision'=>$this->instrument->precision($row['quantity_step']),
            'minimum_quantity'=>$row['min_quantity'],'minimum_notional'=>$row['minimum_notional'],
            'maximum_leverage'=>$row['max_leverage'],'funding_supported'=>true,
            'funding_interval_seconds'=>$row['funding_interval_minutes']*60,
            'funding_cap'=>$row['upper_funding_rate'],'funding_floor'=>$row['lower_funding_rate'],
            'mark_price_source'=>'BYBIT_MARK_PRICE','index_price_source'=>'BYBIT_INDEX_PRICE',
            'liquidation_model_reference'=>'BYBIT_V5_LINEAR',
        ]);
    }

    private function book(RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit perpetual orderbook payload is missing.');
        $book=$this->orderBooks->parse($body,$event->externalInstrument);
        return $this->decoded(MarketEventType::OrderBookSnapshot,$event,$timestamp,$status,['bids'=>$book['bids'],'asks'=>$book['asks']]);
    }

    /** @return array<string,string> */
    private function tickerRow(RawMarketEvent $event):array
    {
        $body=$event->rawPayload['ticker_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit perpetual ticker payload is missing.');
        return $this->ticker->parse($body,$event->externalInstrument);
    }

    /** @param array<string,mixed> $values */
    private function decoded(MarketEventType $type,RawMarketEvent $event,DateTimeImmutable $timestamp,MarketStatus $status,array $values):DecodedMarketEvent
    {
        return new DecodedMarketEvent(
            $type,$event->externalInstrument,$timestamp,$event->sequence,$values,
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference,
        );
    }
}
