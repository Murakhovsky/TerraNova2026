<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use InvalidArgumentException;

/** Canonical BBO route for public Binance USD-M REST and WS observations. */
final readonly class BinanceUsdmBboMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(private BinanceSpotPayloadParser $parser){}

    public function adapterType():string
    {
        return BinanceUsdmBboMarketDataAdapter::ADAPTER_TYPE;
    }

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType()
            ||$event->eventType!=='binance.usdm.ticker.bbo'){
            throw new InvalidArgumentException('Binance USD-M BBO decoder source/type mismatch.');
        }
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body===''){
            throw new InvalidArgumentException('Missing Binance USD-M canonical BBO evidence.');
        }
        $status=match((string)($event->transportMetadata['market_status']??'UNKNOWN')){
            'OPEN'=>MarketStatus::Open,'CLOSED'=>MarketStatus::Closed,default=>MarketStatus::Unknown,
        };
        return new DecodedMarketEvent(
            MarketEventType::Bbo,$event->externalInstrument,
            $event->providerTimestamp??$event->receivedAt,null,
            $this->parser->bbo($body,$event->externalInstrument),
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference,
        );
    }
}
