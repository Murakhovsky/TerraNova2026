<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use InvalidArgumentException;
use JsonException;

/**
 * Bounded Binance Spot bookTicker multi-symbol protocol, no L2 guarantees.
 * One source lease owns the complete socket; symbol failure never publishes
 * a sibling quote as its recovery evidence.
 */
final class BinanceSpotMultiBboProtocol
{
    /** @var array<string,string> */
    private array $symbolsToKeys=[];
    /** @var array<string,string> */
    private array $sequences=[];
    /** @var array<string,DateTimeImmutable> */
    private array $lastReceived=[];
    /** @var array<string,true> */
    private array $quarantined=[];
    /** @var array<string,true> */
    private array $hardRecovery=[];
    /** @var array<string,true> */
    private array $subscribed=[];
    /** @var array<int,array{action:string,symbol:?string}> */
    private array $pending=[];
    private bool $ready=false;
    private int $nextId=0;

    /** @param array<string,string> $keyToSymbol */
    public function __construct(
        private readonly MarketSourceId $sourceId,
        private readonly VenueId $venueId,
        array $keyToSymbol,
        private readonly BinanceSpotPayloadParser $parser,
    ){
        if(count($keyToSymbol)<2||count($keyToSymbol)>100){
            throw new DomainException('Binance Spot batch permits 2..100 BBO symbols.');
        }
        foreach($keyToSymbol as $key=>$symbol){
            if(!is_string($key)||trim($key)===''||!is_string($symbol)
                ||preg_match('/^[A-Z0-9]{3,40}$/D',$symbol)!==1
                ||isset($this->symbolsToKeys[$symbol])){
                throw new DomainException('Invalid or repeated Binance Spot batch mapping.');
            }
            $this->symbolsToKeys[$symbol]=$key;
        }
        ksort($this->symbolsToKeys,SORT_STRING);
    }

    /** @return list<string> */
    public function symbols():array{return array_keys($this->symbolsToKeys);}

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        $expected=array_values($this->symbolsToKeys);
        sort($expected,SORT_STRING);
        sort($keys,SORT_STRING);
        if($expected!==$keys||$generation<1||$generation>PHP_INT_MAX-10000){
            throw new DomainException('Binance batch desired keys or epoch mismatch.');
        }
        $this->ready=false;
        $this->sequences=[];
        $this->lastReceived=[];
        $this->quarantined=[];
        $this->hardRecovery=[];
        $this->subscribed=[];
        $this->pending=[];
        $this->nextId=$generation;
        $this->pending[$generation]=['action'=>'initial','symbol'=>null];
        return [$this->frame('SUBSCRIBE',$this->symbols(),$generation)];
    }

    public function quarantineSymbol(string $symbol,bool $hard=false):void
    {
        if(!isset($this->symbolsToKeys[$symbol]))throw new DomainException('Unknown Binance quarantine symbol.');
        $this->quarantined[$symbol]=true;
        if($hard)$this->hardRecovery[$symbol]=true;
    }

    /** @return string */
    public function unsubscribeFrame(string $symbol):string
    {
        if(!$this->ready||!isset($this->hardRecovery[$symbol])
            ||!isset($this->subscribed[$symbol])||$this->hasPending($symbol)){
            throw new DomainException('Binance unsubscribe requires a hard-quarantined active symbol.');
        }
        $id=$this->nextRequest('unsubscribe',$symbol);
        return $this->frame('UNSUBSCRIBE',[$symbol],$id);
    }

    public function resubscribeFrame(string $symbol):string
    {
        if(!$this->ready||!isset($this->hardRecovery[$symbol])
            ||isset($this->subscribed[$symbol])||$this->hasPending($symbol)){
            throw new DomainException('Binance resubscribe requires a confirmed unsubscribe.');
        }
        $id=$this->nextRequest('resubscribe',$symbol);
        return $this->frame('SUBSCRIBE',[$symbol],$id);
    }

    /**
     * @return array{kind:string,keys?:list<string>,symbol?:string,raw?:RawMarketEvent,reason?:string}
     */
    public function decode(string $payload,DateTimeImmutable $receivedAt):array
    {
        try{$row=json_decode($payload,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(JsonException){throw new DomainException('Malformed Binance Spot multi-symbol JSON.');}
        if(!is_array($row)||array_is_list($row))throw new DomainException('Invalid Binance multi-symbol websocket object.');
        if(array_key_exists('id',$row)){
            $id=$row['id'];
            if((!is_int($id)&&!is_string($id))||!ctype_digit((string)$id)
                ||(string)(int)$id!==(string)$id||!isset($this->pending[(int)$id])
                ||!array_key_exists('result',$row)||$row['result']!==null
                ||isset($row['error'])||isset($row['code'])){
                throw new DomainException('Binance batch subscription ACK invalid or rejected.');
            }
            $request=$this->pending[(int)$id];
            unset($this->pending[(int)$id]);
            if($request['action']==='initial'){
                if($this->ready)throw new DomainException('Duplicate Binance initial ACK.');
                $this->ready=true;
                foreach($this->symbols() as $symbol){
                    $this->subscribed[$symbol]=true;
                    $this->lastReceived[$symbol]=$receivedAt;
                }
                return ['kind'=>'ack','keys'=>array_values($this->symbolsToKeys)];
            }
            $symbol=$request['symbol'];
            if(!is_string($symbol))throw new DomainException('Binance recovery ACK has no symbol.');
            if($request['action']==='unsubscribe'){
                unset($this->subscribed[$symbol]);
                return ['kind'=>'unsubscribed','symbol'=>$symbol];
            }
            if($request['action']==='resubscribe'){
                $this->subscribed[$symbol]=true;
                // Provider re-ACK ends hard quarantine; first fresh complete
                // BBO still controls re-admission to trusted Market State.
                unset($this->hardRecovery[$symbol],$this->sequences[$symbol]);
                $this->lastReceived[$symbol]=$receivedAt;
                return ['kind'=>'resubscribed','symbol'=>$symbol];
            }
            throw new DomainException('Unexpected Binance batch ACK action.');
        }
        if(!$this->ready)throw new DomainException('Binance batch quote before confirmed subscription.');
        if(isset($row['stream'])&&array_key_exists('data',$row)){
            if(!is_string($row['stream'])||!is_array($row['data'])
                ||array_is_list($row['data']))throw new DomainException('Invalid Binance combined stream.');
            $stream=$row['stream'];
            $row=$row['data'];
            $expected=strtolower((string)($row['s']??'')).'@bookTicker';
            if($stream!==$expected)throw new DomainException('Binance combined stream identity mismatch.');
        }
        $symbol=$row['s']??null;
        if(!is_string($symbol)||!isset($this->symbolsToKeys[$symbol])){
            throw new DomainException('Unrequested Binance bookTicker symbol.');
        }
        if(!isset($this->subscribed[$symbol])||isset($this->hardRecovery[$symbol])){
            return ['kind'=>'quarantined','symbol'=>$symbol];
        }
        try{
            $id=$row['u']??null;
            if((!is_int($id)&&!is_string($id))||!ctype_digit((string)$id)
                ||strlen((string)$id)>20||ltrim((string)$id,'0')===''){
                throw new InvalidArgumentException('Invalid Binance update ID.');
            }
            $sequence=ltrim((string)$id,'0');
            $normalized=json_encode([
                'symbol'=>$symbol,'bidPrice'=>$row['b']??null,
                'bidQty'=>$row['B']??null,'askPrice'=>$row['a']??null,
                'askQty'=>$row['A']??null,
            ],JSON_THROW_ON_ERROR);
            $bbo=$this->parser->bbo($normalized,$symbol);
            // Reject a crossed or locked-invalid BBO before publishing any state.
            if($this->compareDecimal($bbo['bid_price'],$bbo['ask_price'])>0){
                throw new InvalidArgumentException('Crossed Binance bookTicker.');
            }
        }catch(InvalidArgumentException|JsonException){
            $this->quarantineSymbol($symbol,true);
            return ['kind'=>'invalid_quote','symbol'=>$symbol,'reason'=>'BINANCE_INVALID_BBO'];
        }
        if(isset($this->sequences[$symbol])){
            $order=$this->compareInteger($sequence,$this->sequences[$symbol]);
            if($order===0){
                return ['kind'=>'heartbeat','symbol'=>$symbol];
            }
            if($order<0){
                $this->quarantineSymbol($symbol,true);
                return ['kind'=>'reset','symbol'=>$symbol,'reason'=>'BINANCE_SEQUENCE_ROLLBACK'];
            }
        }
        $this->sequences[$symbol]=$sequence;
        $this->lastReceived[$symbol]=$receivedAt;
        unset($this->quarantined[$symbol]);
        return [
            'kind'=>'quote','symbol'=>$symbol,
            'raw'=>new RawMarketEvent(
                'cm-ws-binance-multi-'.bin2hex(random_bytes(12)),
                $this->sourceId,$this->venueId,$symbol,
                'binance.spot.ticker.bbo',null,$receivedAt,null,
                ['raw_json'=>$normalized,'raw_websocket_json'=>$payload],
                ['provider'=>'BINANCE','transport'=>'WEBSOCKET',
                 'channel'=>'BOOK_TICKER','provider_update_id'=>$sequence,
                 'timestamp_authority'=>'RECEIVED_AT','market_status'=>'OPEN',
                 'sequence_policy'=>'MONOTONIC_BBO_PER_INSTRUMENT_NOT_L2'],
            ),
        ];
    }

    /** @return list<string> */
    public function staleSymbols(DateTimeImmutable $now,int $maxAgeSeconds=120):array
    {
        if($maxAgeSeconds<1)throw new DomainException('Invalid Binance freshness threshold.');
        if(!$this->ready)return [];
        $out=[];
        foreach($this->symbolsToKeys as $symbol=>$key){
            if(isset($this->quarantined[$symbol])||!isset($this->subscribed[$symbol]))continue;
            if(isset($this->lastReceived[$symbol])
                &&$now->getTimestamp()-$this->lastReceived[$symbol]->getTimestamp()>$maxAgeSeconds){
                $out[]=$symbol;
            }
        }
        return $out;
    }

    private function hasPending(string $symbol):bool
    {
        foreach($this->pending as $request){
            if($request['symbol']===$symbol)return true;
        }
        return false;
    }

    private function nextRequest(string $action,string $symbol):int
    {
        if($this->nextId>=PHP_INT_MAX)throw new DomainException('Binance subscription request ID exhausted.');
        $id=++$this->nextId;
        $this->pending[$id]=['action'=>$action,'symbol'=>$symbol];
        return $id;
    }

    /** @param list<string> $symbols */
    private function frame(string $method,array $symbols,int $id):string
    {
        return json_encode([
            'method'=>$method,
            'params'=>array_map(static fn(string $sym):string=>strtolower($sym).'@bookTicker',$symbols),
            'id'=>$id,
        ],JSON_THROW_ON_ERROR);
    }

    private function compareInteger(string $a,string $b):int
    {
        return strlen($a)===strlen($b)?(strcmp($a,$b)<=>0):(strlen($a)<=>strlen($b));
    }

    private function compareDecimal(string $a,string $b):int
    {
        [$ai,$af]=array_pad(explode('.',$a,2),2,'');
        [$bi,$bf]=array_pad(explode('.',$b,2),2,'');
        $aInt=ltrim($ai,'0');$bInt=ltrim($bi,'0');
        if(strlen($aInt)!==strlen($bInt))return strlen($aInt)<=>strlen($bInt);
        $cmp=strcmp($aInt,$bInt);
        if($cmp!==0)return $cmp<=>0;
        $len=max(strlen($af),strlen($bf));
        return strcmp(str_pad($af,$len,'0'),str_pad($bf,$len,'0'))<=>0;
    }
}
