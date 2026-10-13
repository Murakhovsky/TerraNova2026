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
        if($event->eventType==='massive.stocks.ws.nbbo'){
            return $this->streamingQuote($event);
        }
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
    /** Streaming BBO uses normalized decimal lexemes, not REST /v2/last fields. */
    private function streamingQuote(RawMarketEvent $event):DecodedMarketEvent
    {
        $json=$event->rawPayload['raw_json']??null;
        if(!is_string($json))throw new InvalidArgumentException('Missing Massive WS NBBO evidence.');
        try{$row=json_decode($json,true,32,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new InvalidArgumentException('Invalid Massive WS NBBO evidence.');}
        if(!is_array($row)||array_is_list($row))throw new InvalidArgumentException('Malformed Massive WS quote.');
        $fields=[];
        foreach([
            'bid_price'=>'bid_price','bid_size'=>'bid_quantity',
            'ask_price'=>'ask_price','ask_size'=>'ask_quantity',
        ] as $field=>$output){
            $value=$row[$field]??null;
            if(!is_string($value)||preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1
                ||preg_match('/^0+(?:\.0+)?$/D',$value)===1){
                throw new InvalidArgumentException('Invalid Massive normalized BBO field.');
            }
            $fields[$output]=$value;
        }
        return new DecodedMarketEvent(
            MarketEventType::Bbo,$event->externalInstrument,
            $event->providerTimestamp??$event->receivedAt,$event->sequence,
            $fields,$event->mode,MarketStatus::Unknown,
            MarketSession::Unknown,ReferenceType::Nbbo,
        );
    }

}
