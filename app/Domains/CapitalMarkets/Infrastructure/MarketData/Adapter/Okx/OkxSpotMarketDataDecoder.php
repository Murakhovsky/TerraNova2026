<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx;

use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\{
    MarketEventType,MarketSourceDescriptor,MarketStatus,RawMarketEvent
};
use InvalidArgumentException;

final readonly class OkxSpotMarketDataDecoder implements MarketDataDecoderInterface
{
    public function __construct(private OkxPublicPayloadParser $parser){}
    public function adapterType():string{return OkxSpotMarketDataAdapter::ADAPTER_TYPE;}
    public function decode(MarketSourceDescriptor $source,RawMarketEvent $raw):DecodedMarketEvent
    {
        if($source->adapterType!==self::adapterType())throw new InvalidArgumentException('Wrong OKX spot decoder source.');
        $status=match($raw->transportMetadata['market_status']??null){
            'OPEN'=>MarketStatus::Open,'CLOSED'=>MarketStatus::Closed,default=>MarketStatus::Unknown
        };
        $type=$raw->eventType;
        if($type==='okx.spot.ticker.bbo'||$type==='okx.spot.ticker.volume'){
            $json=$raw->rawPayload['ticker_json']??null;
            if(!is_string($json))throw new InvalidArgumentException('Missing raw OKX spot ticker.');
            $row=$this->parser->ticker($json,$raw->externalInstrument);
            $values=$type==='okx.spot.ticker.bbo'?[
                'bid_price'=>$this->parser->decimal($row['bidPx']??null,'bid price',true),
                'bid_quantity'=>$this->parser->decimal($row['bidSz']??null,'bid quantity',true),
                'ask_price'=>$this->parser->decimal($row['askPx']??null,'ask price',true),
                'ask_quantity'=>$this->parser->decimal($row['askSz']??null,'ask quantity',true),
            ]:[
                'value'=>$this->parser->decimal($row['vol24h']??null,'24h volume'),
            ];
            return new DecodedMarketEvent(
                $type==='okx.spot.ticker.bbo'?MarketEventType::Bbo:MarketEventType::Volume,
                $raw->externalInstrument,$raw->providerTimestamp??$raw->receivedAt,
                $raw->sequence,$values,$raw->mode,$status,
            );
        }
        if($type==='okx.spot.orderbook.snapshot'){
            $json=$raw->rawPayload['book_json']??null;
            if(!is_string($json))throw new InvalidArgumentException('Missing OKX spot book.');
            $book=$this->parser->book($json);
            $levels=static fn(array $xs):array=>array_map(
                static fn(array $x):array=>[
                    'price'=>$x['price'],'quantity'=>$x['quantity_contracts'],
                    'order_count'=>$x['order_count'],
                ],$xs,
            );
            return new DecodedMarketEvent(
                MarketEventType::OrderBookSnapshot,$raw->externalInstrument,
                $raw->providerTimestamp??$raw->receivedAt,$raw->sequence,
                ['bids'=>$levels($book['bids']),'asks'=>$levels($book['asks'])],
                $raw->mode,$status,
            );
        }
        throw new InvalidArgumentException('Unsupported OKX spot raw event type.');
    }
}
