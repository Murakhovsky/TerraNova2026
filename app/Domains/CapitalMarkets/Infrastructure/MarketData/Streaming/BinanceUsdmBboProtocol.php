<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use JsonException;

/**
 * Binance USDⓈ-M public futures @bookTicker (not COIN-M).
 *
 * The 2026 merged futures feed can include contract family "st": 1=UM,
 * 2=CM; anything other than 1 fails closed. BBO update IDs need only grow,
 * they do not prove contiguous L2 delta sequence recovery.
 */
final class BinanceUsdmBboProtocol
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
        if($subscriptionKey===''||preg_match('/^[A-Z0-9]{3,40}$/D',$symbol)!==1){
            throw new DomainException('Invalid Binance USD-M BBO subscription.');
        }
    }

    public function key():string{return $this->subscriptionKey;}

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        if($keys!==[$this->subscriptionKey]||$generation<1){
            throw new DomainException('Binance USD-M pilot requires one mapped BBO subscription.');
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

    /** @return array{kind:'ack'}|array{kind:'quote',raw:RawMarketEvent,update_id:string} */
    public function decode(string $payload,DateTimeImmutable $receivedAt):array
    {
        try{$row=json_decode($payload,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(JsonException){throw new DomainException('Malformed Binance USD-M websocket JSON.');}
        if(!is_array($row)||array_is_list($row))throw new DomainException('Invalid Binance futures websocket message.');
        if(array_key_exists('id',$row)){
            if($this->pendingId===null||(string)$row['id']!==(string)$this->pendingId
                ||!array_key_exists('result',$row)||$row['result']!==null
                ||isset($row['code'])||isset($row['error'])||$this->acknowledged){
                throw new DomainException('Binance USD-M subscribe ACK mismatch.');
            }
            $this->acknowledged=true;
            return ['kind'=>'ack'];
        }
        if(!$this->acknowledged)throw new DomainException('Binance futures quote before subscription ACK.');
        if(isset($row['stream'])||isset($row['data'])){
            if(($row['stream']??null)!==strtolower($this->symbol).'@bookTicker'
                ||!isset($row['data'])||!is_array($row['data'])){
                throw new DomainException('Binance futures combined stream mismatch.');
            }
            $row=$row['data'];
        }
        if(($row['e']??null)!=='bookTicker'||($row['s']??null)!==$this->symbol){
            throw new DomainException('Incorrect Binance futures event type or symbol.');
        }
        $family=$row['st']??null;
        if($family!==1&&$family!=='1'){
            throw new DomainException('Binance non-USD-M contract type or missing family discriminator.');
        }
        $updateId=$this->digits($row['u']??null,'update_id');
        if($this->lastUpdateId!==null&&$this->compare($updateId,$this->lastUpdateId)<=0){
            throw new DomainException('Binance USD-M BBO out of order or repeated.');
        }
        $eventMs=$this->digits($row['E']??null,'event_time');
        $transactionMs=$this->digits($row['T']??null,'transaction_time');
        $providerAt=ProviderTimestamp::fromMilliseconds($eventMs);
        $tradeAt=ProviderTimestamp::fromMilliseconds($transactionMs);
        if(abs($providerAt->getTimestamp()-$receivedAt->getTimestamp())>120
            ||abs($tradeAt->getTimestamp()-$receivedAt->getTimestamp())>120){
            throw new DomainException('Binance USD-M BBO source event is stale.');
        }
        // Prevent a provider frame with contradictory source clock evidence.
        if($tradeAt->getTimestamp()>$providerAt->getTimestamp()+5){
            throw new DomainException('Binance futures transaction time after event time.');
        }
        $normalized=json_encode([
            'symbol'=>$this->symbol,'bidPrice'=>$row['b']??null,
            'bidQty'=>$row['B']??null,'askPrice'=>$row['a']??null,
            'askQty'=>$row['A']??null,
        ],JSON_THROW_ON_ERROR);
        $this->parser->bbo($normalized,$this->symbol);
        $this->lastUpdateId=$updateId;
        $raw=new RawMarketEvent(
            'cm-binance-usdm-ws-bbo-'.bin2hex(random_bytes(12)),
            $this->sourceId,$this->venueId,$this->symbol,'binance.usdm.ticker.bbo',
            $providerAt,$receivedAt,null,
            ['raw_json'=>$normalized,'raw_websocket_json'=>$payload],
            ['provider'=>'BINANCE_USDM','transport'=>'WEBSOCKET',
             'channel'=>'USD_M_BOOK_TICKER','contract_family'=>'USD_M',
             'event_at_ms'=>$eventMs,'transaction_at_ms'=>$transactionMs,
             'provider_update_id'=>$updateId,'market_status'=>'OPEN',
             'sequence_policy'=>'MONOTONIC_BBO_NOT_L2'],
        );
        return ['kind'=>'quote','raw'=>$raw,'update_id'=>$updateId];
    }

    private function digits(mixed $id,string $field):string
    {
        if((!is_int($id)&&!is_string($id))||!ctype_digit((string)$id)
            ||strlen((string)$id)>20){
            throw new DomainException('Invalid Binance futures '.$field.'.');
        }
        $digits=ltrim((string)$id,'0');
        if($digits==='')throw new DomainException('Zero Binance futures '.$field.'.');
        return $digits;
    }

    private function compare(string $a,string $b):int
    {
        return strlen($a)===strlen($b)?(strcmp($a,$b)<=>0):(strlen($a)<=>strlen($b));
    }
}
