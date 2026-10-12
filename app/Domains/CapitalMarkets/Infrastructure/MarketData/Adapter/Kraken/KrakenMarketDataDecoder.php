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

    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        if($source->adapterType!==$this->adapterType())throw new InvalidArgumentException('Kraken decoder source mismatch.');
        $body=$event->rawPayload['raw_json']??null;
        if(!is_string($body)||$body==='')throw new InvalidArgumentException('Kraken raw JSON missing.');
        $status=match((string)($event->transportMetadata['market_status']??'UNKNOWN')){
            'OPEN'=>MarketStatus::Open,
            'CLOSED'=>MarketStatus::Closed,
            default=>MarketStatus::Unknown,
        };
        $timestamp=$event->providerTimestamp??$event->receivedAt;

        return match($event->eventType){
            'kraken.spot.ticker.bbo'=>$this->bbo($event,$body,$timestamp,$status),
            'kraken.spot.ws.ticker.bbo'=>$this->websocketBbo($event,$body,$timestamp,$status),
            'kraken.spot.ticker.volume'=>$this->volume($event,$body,$timestamp,$status),
            'kraken.spot.depth.snapshot'=>$this->book($event,$body,$timestamp,$status),
            default=>throw new InvalidArgumentException('Unsupported Kraken event type: '.$event->eventType),
        };
    }

    private function bbo(RawMarketEvent $event,string $body,\DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $ticker=$this->parser->ticker($body);
        return new DecodedMarketEvent(
            MarketEventType::Bbo,$event->externalInstrument,$timestamp,$event->sequence,
            ['bid_price'=>$ticker['bid'],'bid_quantity'=>$ticker['bid_size'],'ask_price'=>$ticker['ask'],'ask_quantity'=>$ticker['ask_size']],
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference
        );
    }

    /** Streaming feed delivers canonical decimal strings, not REST ticker arrays. */
    private function websocketBbo(
        RawMarketEvent $event,string $body,\DateTimeImmutable $timestamp,MarketStatus $status,
    ):DecodedMarketEvent{
        try{$row=json_decode($body,true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new InvalidArgumentException('Invalid Kraken WebSocket BBO.');}
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('Invalid Kraken BBO object.');
        $values=[];
        foreach(['bid'=>'bid_price','bid_qty'=>'bid_quantity','ask'=>'ask_price','ask_qty'=>'ask_quantity'] as $field=>$output){
            $value=$row[$field]??null;
            if(!is_string($value)||preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/D',$value)!==1
                ||preg_match('/^0+(?:\\.0+)?$/D',$value)===1){
                throw new InvalidArgumentException('Invalid Kraken BBO decimal evidence.');
            }
            $values[$output]=$value;
        }
        return new DecodedMarketEvent(
            MarketEventType::Bbo,$event->externalInstrument,$timestamp,null,$values,
            $event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference,
        );
    }

    private function volume(RawMarketEvent $event,string $body,\DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $ticker=$this->parser->ticker($body);
        return new DecodedMarketEvent(
            MarketEventType::Volume,$event->externalInstrument,$timestamp,$event->sequence,
            ['value'=>$ticker['volume24h']],$event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference
        );
    }

    private function book(RawMarketEvent $event,string $body,\DateTimeImmutable $timestamp,MarketStatus $status):DecodedMarketEvent
    {
        $depth=$this->parser->depth($body);
        return new DecodedMarketEvent(
            MarketEventType::OrderBookSnapshot,$event->externalInstrument,$timestamp,$event->sequence,
            ['bids'=>$depth['bids'],'asks'=>$depth['asks']],$event->mode,$status,MarketSession::Unknown,ReferenceType::ProviderReference
        );
    }
}
