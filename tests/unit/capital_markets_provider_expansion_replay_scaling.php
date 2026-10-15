<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Application\Service\{CrossVenueBboConsensus,StreamingFleetPlacementPolicy};
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlRawMarketProvenance;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Okx\{
    OkxPublicPayloadParser,OkxSpotMarketDataAdapter,OkxSpotMarketDataDecoder
};
use Domains\CapitalMarkets\Application\Contract\MarketJsonHttpClientInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\MarketData\{
    MarketSourceDescriptor,MarketSourceId,MarketSourceRole,MarketDataCapability,
    MarketDataInstrumentTarget,RateLimitPolicy,ReconnectPolicy,MarketHealthPolicy,
    MarketDataMode,MarketEventType
};
use Domains\CapitalMarkets\Domain\Venue\{VenueId,VenueInstrument,VenueInstrumentStatus};
use Domains\CapitalMarkets\Domain\Instrument\{
    InstrumentDescriptor,InstrumentId,InstrumentFamily,InstrumentStatus
};
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
$assert=static function(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);};
$now=gmdate('Y-m-d H:i:s');
$evidence=static fn(string $src,string $venue,string $bid,string $ask,int $priority=50):array=>[
    'source_id'=>$src,'venue_id'=>$venue,'instrument_id'=>'instrument:btc',
    'canonical_event_id'=>'event:'.$src,'quote_asset'=>'USDT',
    'bid_price'=>$bid,'ask_price'=>$ask,'data_mode'=>'LIVE',
    'trust_status'=>'TRUSTED','quality_score'=>99,'source_enabled'=>true,
    'clock_reliable'=>true,'subscription_active'=>true,'source_priority'=>$priority,
    'received_at'=>$now,
];
$policy=new CrossVenueBboConsensus();
$good=$policy->evaluate('instrument:btc',[
    $evidence('binance','venue:binance','100','100.02',10),
    $evidence('okx','venue:okx','100.01','100.03',20),
]);
$assert($good['status']==='CONSENSUS'&&$good['preferred_source_id']==='binance'
    &&$good['backup_source_ids']===['okx']
    &&$good['for_execution']===false,
    'Legitimate same-instrument market backup was not proposed read-only.');
$far=$policy->evaluate('instrument:btc',[
    $evidence('binance','venue:binance','100','100.01'),
    $evidence('okx','venue:okx','105','105.01'),
]);
$assert($far['status']==='DIVERGED'&&$far['preferred_source_id']===null,
    'Cross-venue divergence incorrectly auto-selected primary.');
$wrong=$evidence('okx','venue:okx','100','100.01');
$wrong['instrument_id']='instrument:aaplx';
$assert($policy->evaluate('instrument:btc',[$evidence('binance','venue:binance','100','100.02'),$wrong])['status']==='NO_QUORUM',
    'Different canonical instruments were treated as price-equivalent.');
$curr=$evidence('okx','venue:okx','100','100.02');$curr['quote_asset']='USD';
$assert($policy->evaluate('instrument:btc',[$evidence('binance','venue:binance','100','100.02'),$curr])['status']==='CURRENCY_MISMATCH',
    'USD/USDT conversion invented without verified conversion rate.');
$expired=$evidence('okx','venue:okx','100','100.02');$expired['received_at']='2020-01-01 00:00:00';
$assert($policy->evaluate('instrument:btc',[$evidence('binance','venue:binance','100','100.02'),$expired])['status']==='NO_QUORUM',
    'Stale venue was accepted as a live backup.');
$duplicate=false;
try{$policy->evaluate('instrument:btc',[$evidence('binance','a','100','101'),$evidence('binance','b','100','101')]);}
catch(DomainException){$duplicate=true;}
$assert($duplicate,'Duplicated provider source counted as independent quorum.');

$pool=[];
for($i=1;$i<=40;$i++)$pool[]=['source_id'=>'src-'.$i,'subscriptions'=>25];
$placement=new StreamingFleetPlacementPolicy();
$plan=$placement->place($pool,['node-c','node-a','node-b'],16,450,1000,10,1);
$planReverse=$placement->place(array_reverse($pool),['node-b','node-c','node-a'],16,450,1000,10,1);
$assert($plan===$planReverse,'Stable source-to-node placement depends on insertion order.');
$all=[];
foreach($plan['nodes'] as $node=>$row){
    $assert(count($row['sources'])<=16&&$row['subscriptions']<=450
        &&$row['estimated_milliusd_hour']<=1000,'Per-node quota/cost exceeded.');
    foreach($row['sources'] as $id)$all[$id]=($all[$id]??0)+1;
}
$assert(count($all)===40&&!in_array(2,$all,true)&&$plan['live_changes']===false,
    'One source dispatched to multiple workers or skipped.');
$budgetRejected=false;
try{$placement->place($pool,['only-one'],16,450,100);}catch(DomainException){$budgetRejected=true;}
$assert($budgetRejected,'Overbudget fleet silently admitted.');
$payload=['z'=>['v'=>'0.01','u'=>'100'],'a'=>'BTC'];
$permuted=['a'=>'BTC','z'=>['u'=>'100','v'=>'0.01']];
$assert(MysqlRawMarketProvenance::jsonHash($payload)===
    MysqlRawMarketProvenance::jsonHash($permuted),'JSON field ordering changed raw integrity digest.');
$hash=MysqlRawMarketProvenance::jsonHash($payload);
$changed=MysqlRawMarketProvenance::jsonHash(['z'=>['v'=>'0.02','u'=>'100'],'a'=>'BTC']);
$assert(!hash_equals($hash,$changed),'Payload mutation escaped SHA integrity.');

$http=new class implements MarketJsonHttpClientInterface {
    public array $calls=[];
    public function get(string $org,string $service,string $url,array $headers,array $hosts):string {
        $this->calls[]=[$org,$service,$url,$headers,$hosts];
        if(str_contains($url,'/market/ticker?')){
            return '{"code":"0","data":[{"instType":"SPOT","instId":"BTC-USDT",'.
                '"bidPx":"100.001","bidSz":"2.5","askPx":"100.009",'.
                '"askSz":"2.75","vol24h":"123.400","ts":"1760000000123"}]}';
        }
        if(str_contains($url,'/market/books?')){
            return '{"code":"0","data":[{"bids":[["100.001","2.5","0","1"]],'.
                '"asks":[["100.009","2.75","0","1"]],'.
                '"ts":"1760000000123","seqId":"76"}]}';
        }
        throw new RuntimeException('Fixture must not call unknown provider endpoint.');
    }
};
$clock=new class implements MarketClockInterface {
    public function now():DateTimeImmutable{return new DateTimeImmutable('2025-10-09T08:00:00+00:00');}
    public function reliable():bool{return true;}
};
$venue=VenueId::fromString('venue:okx');
$id=InstrumentId::fromString('instrument:btc');
$instrument=new InstrumentDescriptor($id,'BTC','BTC','Bitcoin',InstrumentFamily::CryptoSpot,
    InstrumentStatus::Active,null,new AssetCode('USDT'),null,null,
    $venue->value(),[],new DateTimeImmutable('2025-01-01'),new DateTimeImmutable('2025-01-01'));
$mapping=new VenueInstrument($venue,$id,'BTC-USDT',VenueInstrumentStatus::Active,3,8);
$target=new MarketDataInstrumentTarget($instrument,$mapping,'BTC-USDT');
$source=new MarketSourceDescriptor(MarketSourceId::fromString('source:okx'),$venue,
    OkxSpotMarketDataAdapter::ADAPTER_TYPE,true,20,[MarketSourceRole::ValidationSource],
    null,new RateLimitPolicy(10,2),new ReconnectPolicy(),new MarketHealthPolicy(),[],'PUBLIC-LIVE');
$parser=new OkxPublicPayloadParser();
$adapter=new OkxSpotMarketDataAdapter($http,$clock,$parser,new VenueMarketStatusResolver());
$batch=$adapter->getSnapshot('ci',$source,$target,
    [MarketDataCapability::Bbo,MarketDataCapability::Volume,MarketDataCapability::OrderBook]);
$assert(count($batch->events)===3&&count($http->calls)===2,
    'OKX Spot did not batch three capabilities into two verified public calls.');
$decoder=new OkxSpotMarketDataDecoder($parser);
$bbo=$decoder->decode($source,$batch->events[0]);
$book=$decoder->decode($source,$batch->events[2]);
$assert($bbo->eventType===MarketEventType::Bbo
    &&$bbo->values['bid_price']==='100.001'
    &&$bbo->values['ask_price']==='100.009'
    &&$book->eventType===MarketEventType::OrderBookSnapshot
    &&$book->sequence==='76','OKX exact string prices, orderbook sequence or event types changed.');
$raw=$batch->events[0];
$replay=new Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent(
    $raw->eventId,$raw->sourceId,$raw->venueId,$raw->externalInstrument,$raw->eventType,
    $raw->providerTimestamp,$raw->receivedAt,$raw->sequence,$raw->rawPayload,$raw->transportMetadata,
    MarketDataMode::Replay
);
$assert($decoder->decode($source,$replay)->mode===MarketDataMode::Replay,
    'OKX spot decoder escaped REPLAY mode.');
$assert($http->calls[0][4]===['www.okx.com'],'OKX market-data SSRF allowlist changed.');
$assert($adapter->resolveInstrument('ci',$source,
    new MarketDataInstrumentTarget($instrument,$mapping,'BTCUSDT'))===null,
    'OKX accepted a non-canonical spot venue symbol.');
echo "P1 provider consensus/OKX Spot and P2 deterministic 40-source placement/provenance unit passed.\n";
