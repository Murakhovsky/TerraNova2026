<?php
declare(strict_types=1);
namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;
use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\{MarketSourceId,RawMarketEvent};
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;

/** Bybit V5 tickers.SYMBOL: ACK-gated snapshot/delta; lifecycle comes from REST. */
final class BybitLinearDerivativesProtocol
{
    private ?string $request=null;
    private ?string $lastTime=null;
    private bool $acked=false;
    private bool $snapshotted=false;
    private array $values=[];
    private const FIELDS=['markPrice'=>'bybit.linear.ws.derivative.mark_price',
        'indexPrice'=>'bybit.linear.ws.derivative.index_price',
        'openInterest'=>'bybit.linear.ws.derivative.open_interest',
        'fundingRate'=>'bybit.linear.ws.derivative.funding'];
    /** @param list<string> $keys */
    public function __construct(
        private readonly MarketSourceId $source,private readonly VenueId $venue,
        private readonly string $symbol,private readonly array $keys,
        private readonly int $intervalSeconds,private readonly ?string $cap,
        private readonly ?string $floor,
    ){
        if(preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,119}$/D',$symbol)!==1
            ||count($keys)!==4||count(array_unique($keys))!==4||$intervalSeconds<60){
            throw new DomainException('Invalid derivative stream scope.');
        }
    }
    public function keys():array{return $this->keys;}
    public function subscribeFrames(array $keys,int $epoch):array{
        if($epoch<1||$keys!==$this->keys)throw new DomainException('Derivative topology changed.');
        $this->request='cm-derivatives-'.$epoch;
        $this->acked=false;$this->snapshotted=false;$this->lastTime=null;$this->values=[];
        return [json_encode(['op'=>'subscribe','req_id'=>$this->request,
            'args'=>['tickers.'.$this->symbol]],JSON_THROW_ON_ERROR)];
    }
    /** @return array{kind:string,events?:list<RawMarketEvent>} */
    public function decode(string $frame,DateTimeImmutable $received):array{
        if(strlen($frame)>2097152)throw new DomainException('Derivatives frame too large.');
        try{$msg=json_decode($frame,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(\JsonException){throw new DomainException('Invalid Bybit derivative JSON.');}
        if(!is_array($msg)||array_is_list($msg))throw new DomainException('Invalid derivatives frame.');
        if(($msg['op']??null)==='pong'||(($msg['op']??null)==='ping'
            &&($msg['ret_msg']??null)==='pong'))return ['kind'=>'pong'];
        if(($msg['op']??null)==='subscribe'){
            if(($msg['success']??null)!==true||($msg['req_id']??null)!==$this->request
                ||$this->acked)throw new DomainException('Derivative subscribe ACK mismatch.');
            $this->acked=true;return ['kind'=>'ack'];
        }
        if(!$this->acked)throw new DomainException('Derivative frame before ACK.');
        if(($msg['topic']??null)!=='tickers.'.$this->symbol
            ||!in_array($msg['type']??null,['snapshot','delta'],true)){
            throw new DomainException('Unexpected derivative topic or update type.');
        }
        $kind=$msg['type'];
        if(!$this->snapshotted&&$kind!=='snapshot')throw new DomainException('Derivative delta without initial snapshot.');
        $data=$msg['data']??null;
        if(!is_array($data)||array_is_list($data)||($data['symbol']??null)!==$this->symbol){
            throw new DomainException('Derivative symbol mismatch.');
        }
        $ms=$msg['ts']??null;
        if((!is_string($ms)&&!is_int($ms))||!ctype_digit((string)$ms)
            ||strlen((string)$ms)>17)throw new DomainException('Derivative timestamp invalid.');
        $ms=(string)$ms;$time=ProviderTimestamp::fromMilliseconds($ms);
        if(abs($received->getTimestamp()-$time->getTimestamp())>120
            ||($this->lastTime!==null&&$this->compare($ms,$this->lastTime)<0)){
            throw new DomainException('Derivative source clock stale or rollback.');
        }
        if($kind==='snapshot')$this->values=[];
        $changed=[];
        foreach(self::FIELDS as $field=>$type){
            if(!array_key_exists($field,$data))continue;
            $value=$data[$field];
            if(!is_string($value)||strlen($value)>80
                ||preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value)!==1
                ||($field!=='fundingRate'&&str_starts_with($value,'-'))
                ||(in_array($field,['markPrice','indexPrice'],true)
                    &&preg_match('/^0+(?:\.0+)?$/',$value)===1)){
                throw new DomainException('Invalid derivative decimal field.');
            }
            $changed[$field]=$value;$this->values[$field]=$value;
        }
        if(array_key_exists('nextFundingTime',$data)){
            $next=$data['nextFundingTime'];
            if((!is_string($next)&&!is_int($next))||!ctype_digit((string)$next)
                ||strlen((string)$next)>17||(string)$next==='0'){
                throw new DomainException('Invalid next funding timestamp.');
            }
            $this->values['nextFundingTime']=(string)$next;
        }
        if($kind==='snapshot'){
            foreach([...array_keys(self::FIELDS),'nextFundingTime'] as $field){
                if(!isset($this->values[$field]))throw new DomainException('Partial derivative initial snapshot.');
            }
            $this->snapshotted=true;
        }
        $this->lastTime=$ms;
        if($changed===[])return ['kind'=>'heartbeat'];
        $events=[];
        foreach($changed as $field=>$value){
            $payload=['value'=>$value];
            if($field==='fundingRate'){
                $payload+=['next_settlement_at'=>$this->values['nextFundingTime'],
                    'funding_interval_seconds'=>$this->intervalSeconds,
                    'cap'=>$this->cap,'floor'=>$this->floor,
                    'rate_type'=>'NORMALIZED_LONGS_PAY_SHORTS','status'=>'CURRENT'];
            }
            $events[]=new RawMarketEvent(
                'cm-ws-derivatives-'.bin2hex(random_bytes(12)),
                $this->source,$this->venue,$this->symbol,self::FIELDS[$field],
                $time,$received,$ms,$payload,
                ['provider'=>'BYBIT','transport'=>'WEBSOCKET',
                    'topic'=>'tickers.'.$this->symbol,'market_status'=>'OPEN',
                    'contract_family'=>'LINEAR_PERPETUAL','ack'=>'VERIFIED'],
            );
        }
        return ['kind'=>$kind,'events'=>$events];
    }
    private function compare(string $a,string $b):int{
        $a=ltrim($a,'0')?:'0';$b=ltrim($b,'0')?:'0';
        return strlen($a)<=>strlen($b) ?: strcmp($a,$b);
    }
}
