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

final readonly class BybitMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(
        private BybitTickerPayloadParser $parser,
        private BybitOrderBookPayloadParser $orderBooks,
    ){}

    public function adapterType():string{return BybitSpotMarketDataAdapter::ADAPTER_TYPE;}

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType())throw new InvalidArgumentException('Bybit decoder received another adapter type.');
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit raw JSON is missing.');
        $timestamp=$event->providerTimestamp??$event->receivedAt;
        $marketStatus=match((string)($event->transportMetadata['market_status']??'UNKNOWN')){
            'OPEN'=>MarketStatus::Open,
            'CLOSED'=>MarketStatus::Closed,
            default=>MarketStatus::Unknown,
        };

        return match($event->eventType){
            'bybit.spot.ticker.bbo'=>$this->bbo($event,$body,$timestamp,$marketStatus),
            'bybit.spot.ticker.volume'=>$this->volume($event,$body,$timestamp,$marketStatus),
            'bybit.spot.orderbook.snapshot'=>$this->book($event,$body,$timestamp,$marketStatus),
            default=>throw new InvalidArgumentException('Unsupported Bybit raw event type: '.$event->eventType),
        };
    }

    private function bbo(RawMarketEvent $event,string $body,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $ticker=$this->parser->parse($body,$event->externalInstrument);
        return new DecodedMarketEvent(
            MarketEventType::Bbo,$event->externalInstrument,$timestamp,$event->sequence,
            [
                'bid_price'=>$ticker['bid1Price'],
                'bid_quantity'=>$ticker['bid1Size'],
                'ask_price'=>$ticker['ask1Price'],
                'ask_quantity'=>$ticker['ask1Size'],
            ],
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference,
        );
    }

    private function volume(RawMarketEvent $event,string $body,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $ticker=$this->parser->parse($body,$event->externalInstrument);
        return new DecodedMarketEvent(
            MarketEventType::Volume,$event->externalInstrument,$timestamp,$event->sequence,
            ['value'=>$ticker['volume24h']],
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference,
        );
    }

    private function book(RawMarketEvent $event,string $body,DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $book=$this->orderBooks->parse($body,$event->externalInstrument);
        return new DecodedMarketEvent(
            MarketEventType::OrderBookSnapshot,$event->externalInstrument,$timestamp,$event->sequence,
            ['bids'=>$book['bids'],'asks'=>$book['asks']],
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference,
        );
    }
}
