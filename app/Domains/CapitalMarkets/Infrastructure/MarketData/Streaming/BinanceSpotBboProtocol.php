<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use JsonException;

/**
 * Binance public Spot @bookTicker only.
 * Self-contained BBO is not L2 depth: update IDs are monotonic, not
 * required contiguous. A new connection must receive a new provider ACK.
 */
final class BinanceSpotBboProtocol
{
    private ?int $pendingId=null;
    private bool $acknowledged=false;
    private ?string $lastUpdateId=null;

    public function __construct(
        private readonly MarketSourceId $sourceId,
        private readonly VenueId $venueId,
        private readonly string $subscriptionKey,
        private readonly string $symbol,
        private readonly BinanceSpotPayloadParser $parser,
    ){
        if(!preg_match('/^[A-Z0-9]{3,40}$/D',$symbol))throw new DomainException('Invalid Binance Spot symbol.');
        if($subscriptionKey==='')throw new DomainException('Missing durable subscription key.');
    }

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        if($keys!==[$this->subscriptionKey]||$generation<1){
            throw new DomainException('Binance BBO pilot requires exactly one configured subscription.');
        }
        $this->pendingId=$generation;
        $this->acknowledged=false;
        $this->lastUpdateId=null;
        return [json_encode([
            'method'=>'SUBSCRIBE',
            'params'=>[strtolower($this->symbol).'@bookTicker'],
            'id'=>$generation,
        ],JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array{kind:'ack'}|array{kind:'quote',raw:RawMarketEvent,update_id:string}
     */
    public function decode(string $payload,DateTimeImmutable $receivedAt):array
    {
        try{$row=json_decode($payload,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(JsonException){throw new DomainException('Malformed Binance Spot WebSocket JSON.');}
        if(!is_array($row)||array_is_list($row))throw new DomainException('Invalid Binance websocket object.');
        if(array_key_exists('id',$row)){
            if($this->pendingId===null||(string)$row['id']!==(string)$this->pendingId
                ||!array_key_exists('result',$row)||$row['result']!==null
                ||isset($row['code'])||isset($row['error'])){
                throw new DomainException('Binance subscription rejected or unexpected ACK.');
            }
            if($this->acknowledged)throw new DomainException('Duplicate Binance subscribe ACK.');
            $this->acknowledged=true;
            return ['kind'=>'ack'];
        }
        if(!$this->acknowledged)throw new DomainException('Binance quote arrived before subscription ACK.');
        if(isset($row['stream'])&&isset($row['data'])){
            if(!is_string($row['stream'])||strcasecmp($row['stream'],strtolower($this->symbol).'@bookTicker')!==0
                ||!is_array($row['data']))throw new DomainException('Binance combined stream mismatch.');
            $row=$row['data'];
        }
        if(($row['s']??null)!==$this->symbol)throw new DomainException('Binance websocket symbol mismatch.');
        $updateId=$row['u']??null;
        if((!is_int($updateId)&&!is_string($updateId))||!ctype_digit((string)$updateId)
            ||strlen((string)$updateId)>20||ltrim((string)$updateId,'0')===''){
            throw new DomainException('Invalid Binance bookTicker update identifier.');
        }
        $sequence=ltrim((string)$updateId,'0');
        if($this->lastUpdateId!==null && $this->compare($sequence,$this->lastUpdateId)<=0){
            throw new DomainException('Out-of-order or repeated Binance bookTicker update.');
        }
        $normalized=json_encode([
            'symbol'=>$this->symbol,
            'bidPrice'=>$row['b']??null,
            'bidQty'=>$row['B']??null,
            'askPrice'=>$row['a']??null,
            'askQty'=>$row['A']??null,
        ],JSON_THROW_ON_ERROR);
        // Reuse the production REST parser's decimal and symbol validation.
        $this->parser->bbo($normalized,$this->symbol);
        $this->lastUpdateId=$sequence;
        $raw=new RawMarketEvent(
            'cm-ws-binance-bbo-'.bin2hex(random_bytes(12)),
            $this->sourceId,$this->venueId,$this->symbol,
            'binance.spot.ticker.bbo',null,$receivedAt,null,
            ['raw_json'=>$normalized,'raw_websocket_json'=>$payload],
            ['provider'=>'BINANCE','transport'=>'WEBSOCKET','channel'=>'BOOK_TICKER',
             'timestamp_authority'=>'RECEIVED_AT','provider_update_id'=>$sequence,
             'market_status'=>'OPEN','sequence_policy'=>'MONOTONIC_BBO_NOT_L2'],
        );
        return ['kind'=>'quote','raw'=>$raw,'update_id'=>$sequence];
    }

    public function key():string { return $this->subscriptionKey; }

    private function compare(string $a,string $b):int
    {
        if(strlen($a)!==strlen($b))return strlen($a)<=>strlen($b);
        return strcmp($a,$b)<=>0;
    }
}
