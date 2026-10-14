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
 * Bounded multi-equity Q-channel Massive WebSocket, one connection per source.
 *
 * Provider integrity and recovery state are tracked independently per symbol.
 * A single bad/stale ticker is quarantined and resubscribed without dropping
 * healthy ticker streams. Source-wide failures still belong to the supervisor.
 */
final class MassiveStocksMultiNbboProtocol
{
    /** @var array<string,string> symbol => durable subscription key */
    private array $keysBySymbol=[];
    /** @var array<string,true> */
    private array $acknowledged=[];
    /** @var array<string,true> */
    private array $pendingSubscription=[];
    /** @var array<string,true> */
    private array $unsubscribePending=[];
    /** @var array<string,true> */
    private array $quarantined=[];
    /** @var array<string,true> hard quarantine needs fresh provider subscribe ACK */
    private array $requiresResubscribe=[];
    /** @var array<string,true> soft quarantine can recover on a newer complete quote */
    private array $recoverOnFreshQuote=[];
    /** @var array<string,true> quarantined ticker received a fresh subscribe ACK */
    private array $recoveryArmed=[];
    /** @var array<string,string> */
    private array $lastSequences=[];
    /** @var array<string,string> */
    private array $lastSessionDates=[];
    /** @var array<string,string> */
    private array $lastFingerprints=[];
    /** @var array<string,DateTimeImmutable> */
    private array $ackReceivedAt=[];
    /** @var array<string,DateTimeImmutable> */
    private array $lastQuoteReceivedAt=[];
    private bool $authenticated=false;
    private bool $subscriptionRequested=false;
    private bool $initialSubscriptionsReady=false;

    /** @param array<string,string> $keyToSymbol */
    public function __construct(
        private readonly MarketSourceId $sourceId,
        array $keyToSymbol,
        private readonly MarketDataMode $mode,
        private readonly string $apiKey,
    ){
        if(count($keyToSymbol)<2||count($keyToSymbol)>25){
            throw new DomainException('Massive batch requires between 2 and 25 distinct equities.');
        }
        foreach($keyToSymbol as $key=>$symbol){
            if(!is_string($key)||$key===''||!is_string($symbol)
                ||preg_match('/^[A-Z][A-Z0-9.-]{0,31}$/D',$symbol)!==1
                ||isset($this->keysBySymbol[$symbol])){
                throw new DomainException('Duplicate or invalid Massive batch subscription.');
            }
            $this->keysBySymbol[$symbol]=$key;
        }
        ksort($this->keysBySymbol,SORT_STRING);
        if(!in_array($mode,[MarketDataMode::Live,MarketDataMode::Delayed],true)){
            throw new DomainException('Massive batch feed must be LIVE or DELAYED.');
        }
        if($apiKey===''||strlen($apiKey)>4096
            ||str_contains($apiKey,"\n")||str_contains($apiKey,"\r")){
            throw new DomainException('Missing or invalid Massive private credential.');
        }
    }

    /** @return list<string> */
    public function symbols():array{return array_keys($this->keysBySymbol);}

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        $expected=array_values($this->keysBySymbol);
        sort($expected,SORT_STRING);
        sort($keys,SORT_STRING);
        if($generation<1||$keys!==$expected){
            throw new DomainException('Massive batch subscription set must match persisted intent.');
        }
        $this->authenticated=false;
        $this->subscriptionRequested=false;
        $this->initialSubscriptionsReady=false;
        $this->acknowledged=[];
        $this->pendingSubscription=[];
        $this->unsubscribePending=[];
        $this->quarantined=[];
        $this->requiresResubscribe=[];
        $this->recoverOnFreshQuote=[];
        $this->recoveryArmed=[];
        $this->lastSequences=[];
        $this->lastSessionDates=[];
        $this->lastFingerprints=[];
        $this->ackReceivedAt=[];
        $this->lastQuoteReceivedAt=[];
        return [json_encode(['action'=>'auth','params'=>$this->apiKey],JSON_THROW_ON_ERROR)];
    }

    public function subscriptionFrame():string
    {
        if(!$this->authenticated||$this->subscriptionRequested){
            throw new DomainException('Massive batch cannot subscribe before auth or twice.');
        }
        $this->subscriptionRequested=true;
        $topics=array_map(static fn(string $symbol):string=>'Q.'.$symbol,$this->symbols());
        foreach($this->symbols() as $symbol)$this->pendingSubscription[$symbol]=true;
        return json_encode(['action'=>'subscribe','params'=>implode(',',$topics)],JSON_THROW_ON_ERROR);
    }

    /** @return string JSON provider unsubscribe frame */
    public function unsubscribeFrame(string $symbol):string
    {
        if(!isset($this->keysBySymbol[$symbol])||!isset($this->quarantined[$symbol])
            ||!isset($this->requiresResubscribe[$symbol])||!isset($this->acknowledged[$symbol])
            ||isset($this->unsubscribePending[$symbol])){
            throw new DomainException('Massive ticker cannot be unsubscribed outside quarantine.');
        }
        $this->unsubscribePending[$symbol]=true;
        return json_encode(['action'=>'unsubscribe','params'=>'Q.'.$symbol],JSON_THROW_ON_ERROR);
    }

    /** @return string JSON provider subscribe frame for one quarantined ticker */
    public function resubscribeFrame(string $symbol):string
    {
        if(!$this->authenticated||!$this->subscriptionRequested
            ||!isset($this->keysBySymbol[$symbol])||!isset($this->quarantined[$symbol])
            ||!isset($this->requiresResubscribe[$symbol])
            ||isset($this->acknowledged[$symbol])||isset($this->unsubscribePending[$symbol])
            ||isset($this->pendingSubscription[$symbol])){
            throw new DomainException('Massive ticker recovery requires a completed unsubscribe ACK.');
        }
        $this->pendingSubscription[$symbol]=true;
        return json_encode(['action'=>'subscribe','params'=>'Q.'.$symbol],JSON_THROW_ON_ERROR);
    }

    /**
     * Soft quarantine waits for a newer complete quote. Hard quarantine first
     * requires a fresh provider unsubscribe/subscribe acknowledgement cycle.
     */
    public function quarantineSymbol(string $symbol,bool $requireResubscribe=false):void
    {
        if(!isset($this->keysBySymbol[$symbol]))throw new DomainException('Unknown Massive ticker quarantine request.');
        $this->quarantined[$symbol]=true;
        unset($this->recoveryArmed[$symbol]);
        if($requireResubscribe){
            $this->requiresResubscribe[$symbol]=true;
            unset($this->recoverOnFreshQuote[$symbol]);
        }elseif(!isset($this->requiresResubscribe[$symbol])){
            $this->recoverOnFreshQuote[$symbol]=true;
        }
    }

    /**
     * @return list<array{kind:string,key?:string,raw?:RawMarketEvent,sequence?:string,symbol?:string,reason?:string}>
     */
    public function decodeBatch(string $payload,DateTimeImmutable $receivedAt):array
    {
        $events=StreamingExactJsonNumbers::events($payload);
        if($events===[])throw new DomainException('Empty Massive multi-quote batch.');
        $out=[];
        foreach($events as $event){
            if(($event['ev']??null)==='status'){
                $status=$event['status']??null;
                if($status==='connected'&&!$this->authenticated){
                    $out[]=['kind'=>'connected'];
                    continue;
                }
                if($status==='auth_success'&&!$this->authenticated){
                    $this->authenticated=true;
                    $out[]=['kind'=>'auth'];
                    continue;
                }
                $message=$event['message']??null;
                if($status==='success'&&$this->subscriptionRequested&&is_string($message)
                    &&str_starts_with($message,'unsubscribed from: ')){
                    $topics=$this->topicsFromStatus($message,'unsubscribed from: ');
                    foreach($topics as $symbol){
                        if(!isset($this->keysBySymbol[$symbol])
                            ||!isset($this->unsubscribePending[$symbol])
                            ||!isset($this->acknowledged[$symbol])){
                            throw new DomainException('Unexpected Massive per-symbol unsubscribe ACK.');
                        }
                        unset($this->acknowledged[$symbol],$this->unsubscribePending[$symbol],
                            $this->lastSequences[$symbol],$this->lastSessionDates[$symbol],
                            $this->lastFingerprints[$symbol],$this->lastQuoteReceivedAt[$symbol],
                            $this->ackReceivedAt[$symbol],$this->recoveryArmed[$symbol],
                            $this->recoverOnFreshQuote[$symbol]);
                        $out[]=['kind'=>'unsubscribed','key'=>$this->keysBySymbol[$symbol],'symbol'=>$symbol];
                    }
                    continue;
                }
                if($status==='success'&&$this->subscriptionRequested&&is_string($message)
                    &&str_starts_with($message,'subscribed to: ')){
                    $topics=$this->topicsFromStatus($message,'subscribed to: ');
                    foreach($topics as $symbol){
                        if(!isset($this->keysBySymbol[$symbol])
                            ||!isset($this->pendingSubscription[$symbol])
                            ||isset($this->acknowledged[$symbol])){
                            throw new DomainException('Unexpected or duplicate Massive batch subscribe ACK.');
                        }
                        unset($this->pendingSubscription[$symbol]);
                        $this->acknowledged[$symbol]=true;
                        $this->ackReceivedAt[$symbol]=$receivedAt;
                        if(isset($this->quarantined[$symbol]))$this->recoveryArmed[$symbol]=true;
                        $out[]=['kind'=>'ack','key'=>$this->keysBySymbol[$symbol],'symbol'=>$symbol];
                    }
                    if(count($this->acknowledged)===count($this->keysBySymbol)
                        &&$this->pendingSubscription===[]){
                        $this->initialSubscriptionsReady=true;
                    }
                    continue;
                }
                throw new DomainException('Massive multi-quote authorization or subscription denied.');
            }
            if(($event['ev']??null)!=='Q'){
                throw new DomainException('Unexpected Massive multi-quote event type.');
            }
            $symbol=$event['sym']??null;
            if(!is_string($symbol)||!isset($this->keysBySymbol[$symbol])){
                throw new DomainException('Unknown Massive multi-quote ticker.');
            }
            if(!$this->initialSubscriptionsReady){
                throw new DomainException('Massive ticker data before all initial subscription ACKs.');
            }
            if(!isset($this->acknowledged[$symbol])||isset($this->pendingSubscription[$symbol])
                ||isset($this->unsubscribePending[$symbol])
                ||(isset($this->quarantined[$symbol])&&!isset($this->recoveryArmed[$symbol])
                    &&!isset($this->recoverOnFreshQuote[$symbol]))){
                $out[]=['kind'=>'quarantined','symbol'=>$symbol];
                continue;
            }

            $softRecovery=isset($this->quarantined[$symbol])&&isset($this->recoverOnFreshQuote[$symbol]);
            try{
                $stamp=$this->positiveInteger($event['t']??null,'SIP timestamp');
                $q=$this->positiveInteger($event['q']??null,'quote sequence');
                $providerAt=ProviderTimestamp::fromMilliseconds($stamp);
                $age=$receivedAt->getTimestamp()-$providerAt->getTimestamp();
                if($age< -15||($this->mode===MarketDataMode::Live&&$age>120)
                    ||($this->mode===MarketDataMode::Delayed&&($age<600||$age>1200))){
                    throw new DomainException('Massive stock quote violates feed time entitlement.');
                }
                $sessionDate=$providerAt->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
                if(!array_key_exists('bp',$event)||!array_key_exists('bs',$event)
                    ||!array_key_exists('ap',$event)||!array_key_exists('as',$event)){
                    if(($this->lastSessionDates[$symbol]??null)===$sessionDate
                        &&isset($this->lastSequences[$symbol])
                        &&$this->compare($q,$this->lastSequences[$symbol])<=0){
                        $this->quarantineSymbol($symbol,true);
                        $out[]=['kind'=>'reset','symbol'=>$symbol,'reason'=>'MASSIVE_SEQUENCE_ROLLBACK'];
                        continue;
                    }
                    $this->lastSessionDates[$symbol]=$sessionDate;
                    $this->lastSequences[$symbol]=$q;
                    unset($this->lastFingerprints[$symbol]);
                    if(isset($this->recoveryArmed[$symbol])){
                        unset($this->requiresResubscribe[$symbol],$this->recoveryArmed[$symbol]);
                        $this->recoverOnFreshQuote[$symbol]=true;
                    }else{
                        $this->quarantineSymbol($symbol);
                    }
                    $out[]=$softRecovery
                        ?['kind'=>'quarantined','symbol'=>$symbol]
                        :['kind'=>'one_sided','symbol'=>$symbol,'reason'=>'MASSIVE_ONE_SIDED_NBBO'];
                    continue;
                }
                $bid=$this->decimal($event['bp'],'bid price');
                $bidQty=$this->positiveInteger($event['bs'],'bid size');
                $ask=$this->decimal($event['ap'],'ask price');
                $askQty=$this->positiveInteger($event['as'],'ask size');
                if($this->compareDecimals($bid,$ask)>0){
                    throw new DomainException('Crossed Massive NBBO.');
                }
            }catch(DomainException|\InvalidArgumentException){
                $this->quarantineSymbol($symbol,true);
                $out[]=['kind'=>'invalid_quote','symbol'=>$symbol,'reason'=>'MASSIVE_INVALID_QUOTE'];
                continue;
            }

            $fingerprint=hash('sha256',implode('|',[$bid,$bidQty,$ask,$askQty]));
            if(($this->lastSessionDates[$symbol]??null)===$sessionDate
                &&isset($this->lastSequences[$symbol])){
                $compare=$this->compare($q,$this->lastSequences[$symbol]);
                if($compare===0 && $fingerprint===($this->lastFingerprints[$symbol]??null)){
                    $out[]=['kind'=>'heartbeat','symbol'=>$symbol];
                    continue;
                }
                if($compare<=0){
                    $this->quarantineSymbol($symbol,true);
                    $out[]=['kind'=>'reset','symbol'=>$symbol,'reason'=>'MASSIVE_SEQUENCE_ROLLBACK'];
                    continue;
                }
            }
            $this->lastSessionDates[$symbol]=$sessionDate;
            $this->lastSequences[$symbol]=$q;
            $this->lastFingerprints[$symbol]=$fingerprint;
            $this->lastQuoteReceivedAt[$symbol]=$receivedAt;
            unset($this->quarantined[$symbol],$this->recoveryArmed[$symbol],
                $this->requiresResubscribe[$symbol],$this->recoverOnFreshQuote[$symbol]);
            $json=json_encode([
                'bid_price'=>$bid,'bid_size'=>$bidQty,
                'ask_price'=>$ask,'ask_size'=>$askQty,
            ],JSON_THROW_ON_ERROR);
            $raw=new RawMarketEvent(
                'cm-massive-batch-nbbo-'.bin2hex(random_bytes(12)),
                $this->sourceId,null,$symbol,'massive.stocks.ws.nbbo',
                $providerAt,$receivedAt,$q,
                ['raw_json'=>$json,'provider_json'=>json_encode($event,JSON_THROW_ON_ERROR)],
                [
                    'provider'=>'MASSIVE','transport'=>'WEBSOCKET','channel'=>'Q',
                    'feed_mode'=>$this->mode->value,
                    'provider_sip_ms'=>$stamp,
                    'sequence_policy'=>'MONOTONIC_NONCONTIGUOUS_PER_SYMBOL',
                ],
                $this->mode,
            );
            $out[]=['kind'=>'quote','raw'=>$raw,'sequence'=>$q,'symbol'=>$symbol];
        }
        return $out;
    }

    /**
     * A busy ticker must not hide a dead/illiquid ticker in the same WS.
     * Quarantined symbols remain untrusted until their fresh subscription and
     * first full NBBO have both been observed.
     */
    public function staleSymbol(DateTimeImmutable $now,int $maxAgeSeconds=120):?string
    {
        if($maxAgeSeconds<1||!$this->initialSubscriptionsReady)return null;
        foreach($this->keysBySymbol as $symbol=>$key){
            if(isset($this->quarantined[$symbol]))continue;
            $last=$this->lastQuoteReceivedAt[$symbol]??$this->ackReceivedAt[$symbol]??null;
            if($last!==null&&$now->getTimestamp()-$last->getTimestamp()>$maxAgeSeconds){
                return $symbol;
            }
        }
        return null;
    }

    /** @return list<string> */
    private function topicsFromStatus(string $message,string $prefix):array
    {
        $topics=explode(',',substr($message,strlen($prefix)));
        if($topics===[])throw new DomainException('Empty Massive subscription status.');
        $symbols=[];
        foreach($topics as $topic){
            $topic=trim($topic);
            if(!str_starts_with($topic,'Q.')||strlen($topic)<=2){
                throw new DomainException('Massive status acknowledged a non-NBBO channel.');
            }
            $symbols[]=substr($topic,2);
        }
        if(count(array_unique($symbols))!==count($symbols)){
            throw new DomainException('Duplicate ticker in Massive status acknowledgement.');
        }
        return $symbols;
    }

    private function positiveInteger(mixed $value,string $field):string
    {
        if(!is_string($value)||strlen($value)>20||!ctype_digit($value)){
            throw new DomainException('Invalid Massive batch '.$field.'.');
        }
        $clean=ltrim($value,'0');
        if($clean==='')throw new DomainException('Zero Massive batch '.$field.'.');
        return $clean;
    }

    private function decimal(mixed $value,string $field):string
    {
        if(!is_string($value)||strlen($value)>80
            ||preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1
            ||preg_match('/^0+(?:\.0+)?$/D',$value)===1){
            throw new DomainException('Invalid Massive batch '.$field.'.');
        }
        return $value;
    }

    private function compare(string $a,string $b):int
    {
        return strlen($a)===strlen($b)?(strcmp($a,$b)<=>0):(strlen($a)<=>strlen($b));
    }

    private function compareDecimals(string $a,string $b):int
    {
        [$ai,$af]=array_pad(explode('.',$a,2),2,'');
        [$bi,$bf]=array_pad(explode('.',$b,2),2,'');
        $ai=ltrim($ai,'0');$bi=ltrim($bi,'0');
        if($ai==='' )$ai='0';
        if($bi==='' )$bi='0';
        if(strlen($ai)!==strlen($bi))return strlen($ai)<=>strlen($bi);
        $integer=strcmp($ai,$bi)<=>0;
        if($integer!==0)return $integer;
        $scale=max(strlen($af),strlen($bf));
        return strcmp(str_pad($af,$scale,'0'),str_pad($bf,$scale,'0'))<=>0;
    }
}
