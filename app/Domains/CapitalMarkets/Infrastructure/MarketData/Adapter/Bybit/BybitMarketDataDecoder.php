<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit;

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
    public function __construct(private BybitTickerPayloadParser $parser){}

    public function adapterType():string{return BybitSpotMarketDataAdapter::ADAPTER_TYPE;}

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType())throw new InvalidArgumentException('Bybit decoder received another adapter type.');
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Bybit raw JSON is missing.');
        $ticker=$this->parser->parse($body,$event->externalInstrument);
        $timestamp=$event->providerTimestamp??$event->receivedAt;

        return match($event->eventType){
            'bybit.spot.ticker.bbo'=>new DecodedMarketEvent(
                MarketEventType::Bbo,
                $event->externalInstrument,
                $timestamp,
                $event->sequence,
                [
                    'bid_price'=>$ticker['bid1Price'],
                    'bid_quantity'=>$ticker['bid1Size'],
                    'ask_price'=>$ticker['ask1Price'],
                    'ask_quantity'=>$ticker['ask1Size'],
                ],
                $event->mode,
                MarketStatus::Unknown,
                MarketSession::Unknown,
                ReferenceType::ProviderReference,
            ),
            'bybit.spot.ticker.volume'=>new DecodedMarketEvent(
                MarketEventType::Volume,
                $event->externalInstrument,
                $timestamp,
                $event->sequence,
                ['value'=>$ticker['volume24h']],
                $event->mode,
                MarketStatus::Unknown,
                MarketSession::Unknown,
                ReferenceType::ProviderReference,
            ),
            default=>throw new InvalidArgumentException('Unsupported Bybit raw event type: '.$event->eventType),
        };
    }
}
