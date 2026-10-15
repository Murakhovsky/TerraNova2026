<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

/**
 * Kraken Spot WebSocket v2 ticker, event_trigger=bbo.
 * One instrument per worker until durable per-channel fencing is implemented.
 * First data message must be a provider snapshot. Updates do NOT prove L2.
 */
final class KrakenSpotTickerProtocol
{
    private ?int $requestId=null;
    private bool $acknowledged=false;
    private bool $hasSnapshot=false;
    private ?DateTimeImmutable $lastTimestamp=null;
    private ?string $lastFingerprint=null;

    public function __construct(
        private readonly MarketSourceId $sourceId,
        private readonly VenueId $venueId,
        private readonly string $subscriptionKey,
        private readonly string $symbol,
    ){
        if($subscriptionKey===''||preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/-]{2,119}$~D',$symbol)!==1){
            throw new DomainException('Invalid Kraken ticker subscription mapping.');
        }
    }

    public function key():string{return $this->subscriptionKey;}

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        if($keys!==[$this->subscriptionKey]||$generation<1){
            throw new DomainException('Kraken pilot requires exactly one persisted BBO subscription.');
        }
        $this->requestId=$generation;
        $this->acknowledged=false;
        $this->hasSnapshot=false;
        $this->lastTimestamp=null;
        $this->lastFingerprint=null;
        return [json_encode([
            'method'=>'subscribe','params'=>[
                'channel'=>'ticker','symbol'=>[$this->symbol],
                'event_trigger'=>'bbo','snapshot'=>true,
            ],'req_id'=>$generation,
        ],JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array{kind:'ack'|'heartbeat'|'reset'}
     *     |array{kind:'quote',raw:RawMarketEvent,timestamp:string}
     */
    public function decode(
        string $payload,
        DateTimeImmutable $receivedAt,
        string $marketStatus='UNKNOWN',
    ):array{
        $message=StreamingExactJsonNumbers::object($payload);
        $channel=$message['channel']??null;
        if($channel==='heartbeat'||$channel==='status'){
            return ['kind'=>'heartbeat'];
        }
        if(($message['method']??null)==='subscribe'){
            $result=$message['result']??null;
            if(($message['success']??null)!==true||$this->requestId===null
                ||(string)($message['req_id']??'')!==(string)$this->requestId
                ||!is_array($result)||($result['channel']??null)!=='ticker'
                ||($result['symbol']??null)!==$this->symbol
                ||($result['snapshot']??null)!==true
                ||$this->acknowledged){
                throw new DomainException('Kraken ticker subscription rejected or mismatched.');
            }
            $this->acknowledged=true;
            return ['kind'=>'ack'];
        }
        if(!$this->acknowledged)throw new DomainException('Kraken ticker before subscription ACK.');
        if($channel!=='ticker'||!in_array($message['type']??null,['snapshot','update'],true)){
            throw new DomainException('Unexpected Kraken ticker channel or message type.');
        }
        if(!$this->hasSnapshot&&$message['type']!=='snapshot'){
            throw new DomainException('Kraken ticker initial snapshot is required.');
        }
        $data=$message['data']??null;
        if(!is_array($data)||count($data)!==1||!array_is_list($data)
            ||!is_array($data[0])||array_is_list($data[0])){
            throw new DomainException('Kraken ticker must contain one market observation.');
        }
        $quote=$data[0];
        if(($quote['symbol']??null)!==$this->symbol){
            throw new DomainException('Kraken ticker symbol mismatch.');
        }

        $values=[];
        foreach(['bid','bid_qty','ask','ask_qty'] as $field){
            $value=$quote[$field]??null;
            if(!is_string($value)||strlen($value)>100
                ||preg_match('/^(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?$/D',$value)!==1
                ||preg_match('/^0+(?:\\.0+)?$/D',$value)===1){
                throw new DomainException('Invalid Kraken ticker decimal field: '.$field);
            }
            $values[$field]=$value;
        }
        // Equal/crossed books are not valid best-bid/offer market evidence.
        // Keep full decimal evidence for the canonical quality engine.
        $time=$quote['timestamp']??null;
        if(!is_string($time)||preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d+)?(?:Z|[+-]\\d{2}:\\d{2})$/D',$time)!==1){
            throw new DomainException('Kraken ticker timestamp must be RFC3339.');
        }
        try{$providerAt=(new DateTimeImmutable($time))->setTimezone(new DateTimeZone('UTC'));}
        catch(\Throwable){throw new DomainException('Invalid Kraken provider timestamp.');}
        if(abs($providerAt->getTimestamp()-$receivedAt->getTimestamp())>180){
            throw new DomainException('Kraken provider timestamp too far from receive time.');
        }
        $fingerprint=hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
        if($this->lastTimestamp!==null){
            if($providerAt<$this->lastTimestamp)return ['kind'=>'reset'];
            if($providerAt==$this->lastTimestamp){
                if($fingerprint===$this->lastFingerprint)return ['kind'=>'heartbeat'];
                return ['kind'=>'reset'];
            }
        }
        $this->hasSnapshot=true;
        $this->lastTimestamp=$providerAt;
        $this->lastFingerprint=$fingerprint;
        $raw=new RawMarketEvent(
            'cm-ws-kraken-ticker-'.bin2hex(random_bytes(12)),
            $this->sourceId,$this->venueId,$this->symbol,
            'kraken.spot.ws.ticker.bbo',$providerAt,$receivedAt,null,
            ['raw_json'=>json_encode($values,JSON_THROW_ON_ERROR),'raw_websocket_json'=>$payload],
            [
                'provider'=>'KRAKEN','transport'=>'WEBSOCKET','channel'=>'TICKER_BBO',
                'event_trigger'=>'bbo','timestamp_authority'=>'PROVIDER',
                'market_status'=>$marketStatus,
                'sequence_policy'=>'TIMESTAMP_MONOTONIC_BBO_NOT_L2',
            ],
        );
        return ['kind'=>'quote','raw'=>$raw,'timestamp'=>$time];
    }
}
