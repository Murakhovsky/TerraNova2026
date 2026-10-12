<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\Streaming;

use DateTimeImmutable;
use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitOrderBookPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Time\ProviderTimestamp;
use JsonException;

/**
 * Bybit V5 public Spot orderbook.1 snapshot -> self-contained top-of-book BBO.
 * Orderbook.1 is documented snapshot-only, including same-u idle refreshes.
 * Never use its update id as a contiguous L2-delta sequence.
 */
final class BybitSpotTopBookProtocol
{
    private ?string $requestId=null;
    private bool $acknowledged=false;
    private ?string $lastUpdateId=null;
    private ?string $lastQuoteFingerprint=null;

    public function __construct(
        private readonly MarketSourceId $sourceId,
        private readonly VenueId $venueId,
        private readonly string $subscriptionKey,
        private readonly string $symbol,
        private readonly BybitOrderBookPayloadParser $orderBook,
    ){
        if(!preg_match('/^[A-Z0-9][A-Z0-9._:-]{1,119}$/D',$symbol)
           ||$subscriptionKey==='')throw new DomainException('Invalid Bybit Spot BBO subscription.');
    }

    public function key():string{return $this->subscriptionKey;}

    /** @param list<string> $keys @return list<string> */
    public function subscribeFrames(array $keys,int $generation):array
    {
        if($keys!==[$this->subscriptionKey]||$generation<1){
            throw new DomainException('Bybit Spot BBO pilot requires one persistent subscription.');
        }
        $this->requestId='cm-bybit-'.$generation;
        $this->acknowledged=false;
        $this->lastUpdateId=null;
        $this->lastQuoteFingerprint=null;
        return [json_encode([
            'req_id'=>$this->requestId,
            'op'=>'subscribe',
            'args'=>['orderbook.1.'.$this->symbol],
        ],JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array{kind:'ack'|'heartbeat'|'pong'|'reset'}
     *    |array{kind:'quote',raw:RawMarketEvent,update_id:string}
     */
    public function decode(string $payload,DateTimeImmutable $receivedAt):array
    {
        try{$row=json_decode($payload,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
        catch(JsonException){throw new DomainException('Malformed Bybit Spot WebSocket JSON.');}
        if(!is_array($row)||array_is_list($row))throw new DomainException('Invalid Bybit Spot WS object.');
        if(($row['op']??null)==='ping' && ($row['ret_msg']??null)==='pong'
            && ($row['success']??null)===true){
            return ['kind'=>'pong'];
        }
        if(($row['op']??null)==='subscribe'){
            if(($row['success']??null)!==true||($row['req_id']??null)!==$this->requestId
               ||$this->acknowledged)throw new DomainException('Bybit subscribe ACK rejected.');
            $this->acknowledged=true;
            return ['kind'=>'ack'];
        }
        if(!$this->acknowledged)throw new DomainException('Bybit BBO before subscription acknowledgement.');
        if(($row['topic']??null)!=='orderbook.1.'.$this->symbol
            ||($row['type']??null)!=='snapshot'){
            throw new DomainException('Unexpected Bybit Spot topic or delta.');
        }
        $data=$row['data']??null;
        if(!is_array($data)||array_is_list($data)||($data['s']??null)!==$this->symbol){
            throw new DomainException('Bybit top-book symbol mismatch.');
        }
        foreach(['u','seq'] as $field){
            $id=$data[$field]??null;
            if((!is_int($id)&&!is_string($id))||!ctype_digit((string)$id)
                ||strlen((string)$id)>20||(string)$id==='0'){
                throw new DomainException('Invalid Bybit top-book '.$field.'.');
            }
        }
        $sequence=ltrim((string)$data['u'],'0');
        if($sequence==='')throw new DomainException('Zero Bybit update identifier.');
        $ts=$row['ts']??null;
        if((!is_string($ts)&&!is_int($ts))||!ctype_digit((string)$ts)){
            throw new DomainException('Missing Bybit provider timestamp.');
        }
        $providerTimestamp=ProviderTimestamp::fromMilliseconds($ts);
        $ageSeconds=abs($receivedAt->getTimestamp()-$providerTimestamp->getTimestamp());
        if($ageSeconds>120)throw new DomainException('Bybit top-book provider timestamp is outside tolerance.');
        $normalized=json_encode([
            'retCode'=>0,'time'=>(string)$ts,
            'result'=>[
                's'=>$this->symbol,
                'b'=>$data['b']??null,
                'a'=>$data['a']??null,
                'seq'=>$data['seq'],
                'u'=>$data['u'],
                'cts'=>$row['cts']??$ts,
            ],
        ],JSON_THROW_ON_ERROR);
        $book=$this->orderBook->parse($normalized,$this->symbol);
        if(count($book['bids'])!==1||count($book['asks'])!==1){
            throw new DomainException('Bybit level-one snapshot must contain exactly one bid and ask.');
        }
        $quoteHash=hash('sha256',json_encode([$book['bids'],$book['asks']],JSON_THROW_ON_ERROR));
        if($this->lastUpdateId!==null){
            $compare=$this->compare($sequence,$this->lastUpdateId);
            if($compare===0&&$quoteHash===$this->lastQuoteFingerprint){
                return ['kind'=>'heartbeat']; // 3s idle refresh with same u.
            }
            if($compare<=0){
                return ['kind'=>'reset']; // New exchange epoch or corrupt order.
            }
        }
        $this->lastUpdateId=$sequence;
        $this->lastQuoteFingerprint=$quoteHash;
        $raw=new RawMarketEvent(
            'cm-ws-bybit-topbook-'.bin2hex(random_bytes(12)),
            $this->sourceId,$this->venueId,$this->symbol,'bybit.spot.ws.topbook.bbo',
            $providerTimestamp,$receivedAt,null,
            ['raw_json'=>$normalized,'raw_websocket_json'=>$payload],
            ['provider'=>'BYBIT','transport'=>'WEBSOCKET','channel'=>'ORDERBOOK_LEVEL_1',
             'market_status'=>'OPEN','provider_update_id'=>$sequence,
             'provider_cross_sequence'=>(string)$data['seq'],
             'sequence_policy'=>'FULL_BBO_SNAPSHOT_NOT_L2_DELTA'],
        );
        return ['kind'=>'quote','raw'=>$raw,'update_id'=>$sequence];
    }

    private function compare(string $a,string $b):int
    {
        if(strlen($a)!==strlen($b))return strlen($a)<=>strlen($b);
        return strcmp($a,$b)<=>0;
    }
}
