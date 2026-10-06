<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\{MarketEventType,MarketSession,MarketSourceDescriptor,MarketStatus,RawMarketEvent,ReferenceType};
use InvalidArgumentException;

final readonly class KrakenMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(private KrakenSpotPayloadParser $parser){}
    public function adapterType():string{return KrakenSpotMarketDataAdapter::ADAPTER_TYPE;}
    public function decode(MarketSourceDescriptor $s,RawMarketEvent $e):DecodedMarketEvent
    {
        if($s->adapterType!==$this->adapterType())throw new InvalidArgumentException('Kraken decoder source mismatch.');
        $body=$e->rawPayload['raw_json']??null;if(!is_string($body)||$body==='')throw new InvalidArgumentException('Kraken raw JSON missing.');
        $status=match((string)($e->transportMetadata['market_status']??'UNKNOWN')){'OPEN'=>MarketStatus::Open,'CLOSED'=>MarketStatus::Closed,default=>MarketStatus::Unknown};
        $ts=$e->providerTimestamp??$e->receivedAt;
        return match($e->eventType){
            'kraken.spot.ticker.bbo'=>($t=$this->parser->ticker($body))&&new DecodedMarketEvent(MarketEventType::Bbo,$e->externalInstrument,$ts,$e->sequence,['bid_price'=>$t['bid'],'bid_quantity'=>$t['bid_size'],'ask_price'=>$t['ask'],'ask_quantity'=>$t['ask_size']],$e->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference),
            'kraken.spot.ticker.volume'=>($t=$this->parser->ticker($body))&&new DecodedMarketEvent(MarketEventType::Volume,$e->externalInstrument,$ts,$e->sequence,['value'=>$t['volume24h']],$e->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference),
            'kraken.spot.depth.snapshot'=>($d=$this->parser->depth($body))&&new DecodedMarketEvent(MarketEventType::OrderBookSnapshot,$e->externalInstrument,$ts,$e->sequence,['bids'=>$d['bids'],'asks'=>$d['asks']],$e->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference),
            default=>throw new InvalidArgumentException('Unsupported Kraken event type: '.$e->eventType),
        };
    }
}
