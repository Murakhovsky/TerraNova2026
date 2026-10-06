<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use InvalidArgumentException;

final readonly class MassiveMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(private MassiveQuotePayloadParser $parser){}

    public function adapterType():string{return MassiveStocksReferenceAdapter::ADAPTER_TYPE;}

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType())throw new InvalidArgumentException('Massive decoder received another adapter type.');
        if($event->eventType!=='massive.stocks.nbbo')throw new InvalidArgumentException('Unsupported Massive raw event type: '.$event->eventType);
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Massive raw JSON is missing.');
        $quote=$this->parser->parse($body,$event->externalInstrument);

        return new DecodedMarketEvent(
            MarketEventType::Bbo,
            $event->externalInstrument,
            $event->providerTimestamp??$event->receivedAt,
            $event->sequence,
            [
                'bid_price'=>$quote['bidPrice'],
                'bid_quantity'=>$quote['bidSize'],
                'ask_price'=>$quote['askPrice'],
                'ask_quantity'=>$quote['askSize'],
            ],
            $event->mode,
            MarketStatus::Unknown,
            MarketSession::Unknown,
            ReferenceType::Nbbo,
        );
    }
}
