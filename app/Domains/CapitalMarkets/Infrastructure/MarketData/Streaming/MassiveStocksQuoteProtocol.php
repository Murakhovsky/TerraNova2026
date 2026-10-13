<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;

/**
 * Massive stock quotes: connected -> authenticate -> auth_success ->
 * subscribe Q.<ticker> -> success -> independent NBBO observations.
 * All numeric JSON tokens retain their original exact decimal lexemes.
 *
 * Auth material MUST never appear in RawMarketEvent, errors or logs.
 */
final class MassiveStocksQuoteProtocol
{
    private bool $authenticated=false;
    private bool $subscribed=false;
    private ?string $lastSequence=null;
    private ?string $lastSessionDate=null;
    private ?string $lastFingerprint=null;

    public function __construct(
        private readonly MarketSourceId $sourceId,
        private readonly string $key,
        private readonly string $symbol,
        private readonly MarketDataMode $mode,
        private readonly string $apiKey,
    ){
        if($key===''||preg_match('/^[A-Z][A-Z0-9.-]{0,31}$/D',$symbol)!==1){
            throw new DomainException('Invalid Massive stock source mapping.');
        }
        if(!in_array($mode,[MarketDataMode::Live,MarketDataMode::Delayed],true)){
            throw new DomainException('Massive WS must declare LIVE or DELAYED mode.');
        }
        if($apiKey===''||strlen($apiKey)>4096||str_contains($apiKey,"\n")
            ||str_contains($apiKey,"\r")){
            throw new DomainException('Invalid Massive WebSocket credential.');
        }
    }

    public function key():string{return $this->key;}

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        if($generation<1||$keys!==[$this->key]){
            throw new DomainException('Massive pilot requires exactly one configured ticker.');
        }
        $this->authenticated=false;
        $this->subscribed=false;
        $this->lastSequence=null;
        $this->lastSessionDate=null;
        $this->lastFingerprint=null;
        // The transport initially sends AUTH, *not* SUBSCRIBE. Subscription
        // follows only after provider's auth_success status message.
        return [json_encode(['action'=>'auth','params'=>$this->apiKey],JSON_THROW_ON_ERROR)];
    }

    public function subscriptionFrame():string
    {
        if(!$this->authenticated||$this->subscribed){
            throw new DomainException('Massive subscription requires fresh authentication.');
        }
        return json_encode([
            'action'=>'subscribe','params'=>'Q.'.$this->symbol,
        ],JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{kind:'connected'|'auth'|'ack'|'heartbeat'|'one_sided'|'reset'}|array{kind:'quote',raw:RawMarketEvent,sequence:string}>
     */
    public function decodeBatch(string $payload,DateTimeImmutable $receivedAt):array
    {
        $events=StreamingExactJsonNumbers::events($payload);
        $results=[];
        foreach($events as $row){
            if(($row['ev']??null)==='status'){
                $status=$row['status']??null;
                if($status==='connected' && !$this->authenticated){
                    $results[]=['kind'=>'connected'];
                    continue;
                }
                if($status==='auth_success' && !$this->authenticated){
                    $this->authenticated=true;
                    $results[]=['kind'=>'auth'];
                    continue;
                }
                if($status==='success' && $this->authenticated && !$this->subscribed
                    && ($row['message']??null)==='subscribed to: Q.'.$this->symbol){
                    $this->subscribed=true;
                    $results[]=['kind'=>'ack'];
                    continue;
                }
                // All other status frames, including auth_failed, error,
                // NOT_AUTHORIZED, wrong-topic ACK and duplicate auth fail.
                throw new DomainException('Massive WebSocket authorization or subscription rejected.');
            }
            if(!$this->authenticated||!$this->subscribed){
                throw new DomainException('Massive stock quote before subscription ACK.');
            }
            if(($row['ev']??null)!=='Q'||($row['sym']??null)!==$this->symbol){
                throw new DomainException('Unexpected Massive stock channel or ticker.');
            }
            $stamp=$this->positiveInteger($row['t']??null,'SIP timestamp');
            $sequence=$this->positiveInteger($row['q']??null,'quote sequence');
            $timestamp=ProviderTimestamp::fromMilliseconds($stamp);
            $age=$receivedAt->getTimestamp()-$timestamp->getTimestamp();
            // Real-time and 15m-delayed feeds must never be mislabeled.
            if($age<(-15) || ($this->mode===MarketDataMode::Live && $age>120)
                ||($this->mode===MarketDataMode::Delayed && ($age<600 || $age>1200))){
                throw new DomainException('Massive quote timestamp contradicts declared feed recency.');
            }

            $hasBid=array_key_exists('bp',$row)&&array_key_exists('bs',$row);
            $hasAsk=array_key_exists('ap',$row)&&array_key_exists('as',$row);
            if(!$hasBid||!$hasAsk){
                // Provider legitimately omits either side (especially 4 AM).
                // Never substitute 0 or publish previous quote as trusted.
                $results[]=['kind'=>'one_sided'];
                continue;
            }
            $bid=$this->positiveDecimal($row['bp'],'bid price');
            $bidQty=$this->positiveInteger($row['bs'],'bid size');
            $ask=$this->positiveDecimal($row['ap'],'ask price');
            $askQty=$this->positiveInteger($row['as'],'ask size');
            $sessionDate=$timestamp->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
            $fingerprint=hash('sha256',implode('|',[$bid,$bidQty,$ask,$askQty]));
            if($this->lastSessionDate===$sessionDate && $this->lastSequence!==null){
                $order=$this->compare($sequence,$this->lastSequence);
                if($order===0&&$fingerprint===$this->lastFingerprint){
                    $results[]=['kind'=>'heartbeat'];
                    continue;
                }
                if($order<=0){
                    $results[]=['kind'=>'reset'];
                    continue;
                }
            }
            $this->lastSessionDate=$sessionDate;
            $this->lastSequence=$sequence;
            $this->lastFingerprint=$fingerprint;
            $normalized=json_encode([
                'bid_price'=>$bid,'bid_size'=>$bidQty,'ask_price'=>$ask,'ask_size'=>$askQty,
            ],JSON_THROW_ON_ERROR);
            $results[]=[
                'kind'=>'quote',
                'sequence'=>$sequence,
                'raw'=>new RawMarketEvent(
                    'cm-massive-stocks-ws-'.bin2hex(random_bytes(12)),
                    $this->sourceId,null,$this->symbol,'massive.stocks.ws.nbbo',
                    $timestamp,$receivedAt,$sequence,
                    ['raw_json'=>$normalized,'provider_json'=>json_encode($row,JSON_THROW_ON_ERROR)],
                    ['provider'=>'MASSIVE','transport'=>'WEBSOCKET','channel'=>'Q',
                     'feed_mode'=>$this->mode->value,'provider_sip_ms'=>$stamp,
                     'sequence_policy'=>'MONOTONIC_NONCONTIGUOUS_SESSION_RESET'],
                    $this->mode,
                ),
            ];
        }
        return $results;
    }

    private function positiveInteger(mixed $value,string $label):string
    {
        if(!is_string($value)||strlen($value)>20||!ctype_digit($value)){
            throw new DomainException('Invalid Massive '.$label.'.');
        }
        $value=ltrim($value,'0');
        if($value==='')throw new DomainException('Zero Massive '.$label.'.');
        return $value;
    }

    private function positiveDecimal(mixed $value,string $label):string
    {
        if(!is_string($value)||strlen($value)>80
            ||preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1
            ||preg_match('/^0+(?:\.0+)?$/D',$value)===1){
            throw new DomainException('Invalid Massive '.$label.'.');
        }
        return $value;
    }

    private function compare(string $left,string $right):int
    {
        return strlen($left)===strlen($right)
            ?(strcmp($left,$right)<=>0)
            :(strlen($left)<=>strlen($right));
    }
}
