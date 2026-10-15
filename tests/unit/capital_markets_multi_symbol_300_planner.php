<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use Domains\CapitalMarkets\Application\Service\{MultiSymbolStreamingCore,StreamingSubscriptionPlanner};
use Domains\CapitalMarkets\Application\Contract\{
    MarketSourceRepositoryInterface,MarketSubscriptionRepositoryInterface,MarketInstrumentResolverInterface
};
use Domains\CapitalMarkets\Application\DTO\ResolvedMarketInstrument;
use Domains\CapitalMarkets\Domain\Instrument\{InstrumentDescriptor,InstrumentId,InstrumentFamily,InstrumentStatus};
use Domains\CapitalMarkets\Domain\MarketData\{
    MarketSourceDescriptor,MarketSourceId,MarketSourceHealth,MarketSourceRole,
    MarketSubscription,MarketSubscriptionId,MarketSubscriptionStatus,
    MarketEventType,RateLimitPolicy,ReconnectPolicy,MarketHealthPolicy,
    MarketDataInstrumentTarget
};
$assert=static function(bool $yes,string $why):void{if(!$yes)throw new RuntimeException($why);};
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$instruments=[];
foreach(range(1,25) as $i){
    $id=InstrumentId::fromString('instrument:ci:'.$i);
    $instruments[$id->value()]=new InstrumentDescriptor(
        $id,'STOCK'.$i,'STOCK'.$i,'Fixture Equity '.$i,
        InstrumentFamily::Equity,InstrumentStatus::Active,null,null,
        null,null,null,[],$now,$now
    );
}
$sources=[];
$subs=[];
for($i=1;$i<=12;$i++){
    $name='source:ci:'.$i;$id=MarketSourceId::fromString($name);
    $sources[$name]=new MarketSourceDescriptor(
        $id,null,'massive.stocks.v2',true,100,[MarketSourceRole::ReferenceSource],
        null,new RateLimitPolicy(1000,60),new ReconnectPolicy(1000,10000,5),
        new MarketHealthPolicy(),['data_mode'=>'LIVE'],'MASSIVE_STOCKS_WS_Q_LIVE'
    );
    foreach($instruments as $instrumentId=>$descriptor){
        $subs[$name][]=new MarketSubscription(
            MarketSubscriptionId::fromString('subscription:'.$i.':'.$instrumentId),
            $id,null,$descriptor->id,MarketEventType::Bbo,
            MarketSubscriptionStatus::Active,$now
        );
    }
}
$sourceRepo=new class($sources) implements MarketSourceRepositoryInterface{
    public function __construct(public array $sources){}
    public function save(string $organizationId,MarketSourceDescriptor $source):void{}
    public function get(string $organizationId,MarketSourceId $id):?MarketSourceDescriptor{
        return $this->sources[$id->value()]??null;
    }
    public function list(string $organizationId,bool $enabledOnly=false):array{
        return array_values($this->sources);
    }
    public function saveHealth(string $organizationId,MarketSourceHealth $health):void{}
    public function health(string $organizationId,MarketSourceId $id):?MarketSourceHealth{return null;}
};
$subscriptionRepo=new class($subs) implements MarketSubscriptionRepositoryInterface{
    public function __construct(public array $subs){}
    public function save(string $organizationId,MarketSubscription $subscription):void{}
    public function forSource(string $organizationId,MarketSourceId $sourceId,bool $activeOnly=false):array{
        $rows=$this->subs[$sourceId->value()]??[];
        return $activeOnly?array_values(array_filter($rows,
            static fn($s):bool=>$s->status===MarketSubscriptionStatus::Active)):$rows;
    }
};
$resolver=new class($instruments) implements MarketInstrumentResolverInterface{
    public function __construct(public array $instruments){}
    public function resolve(string $organizationId,MarketSourceDescriptor $source,
        string $externalInstrument):?ResolvedMarketInstrument{return null;}
    public function target(string $organizationId,MarketSourceDescriptor $source,
        InstrumentId $instrumentId):?MarketDataInstrumentTarget{
        $instrument=$this->instruments[$instrumentId->value()]??null;
        return $instrument===null?null:new MarketDataInstrumentTarget(
            $instrument,null,$instrument->symbol);
    }
};
$planner=new MultiSymbolStreamingCore(
    $sourceRepo,new StreamingSubscriptionPlanner($sourceRepo,$subscriptionRepo),$resolver);
$ids=array_keys($sources);
$plan=$planner->plan('ci-tenant',$ids);
$assert($plan['status']==='PLANNED_NOT_RUNNING'
    &&$plan['totals']['SOURCES']===12&&$plan['totals']['BBO']===300
    &&$plan['network_sessions_opened']===0&&$plan['no_parallel_same_source_shards']===true,
    '300 symbol / 12 fenced-source plan incorrectly counted or started sockets.');
$assert(count($plan['sources'])===12&&$plan['sources'][0]['subscriptions']===25
    &&$plan['sources'][0]['headroom']===0
    &&$plan['sources'][0]['reconnect']['max_attempts']===5,
    'Per-source capacity and reconnect policy lost.');
try{$planner->plan('ci-tenant',[$ids[0],$ids[0]]);throw new RuntimeException('Duplicate source accepted.');}
catch(DomainException $expected){}
$subscriptionRepo->subs[$ids[0]][]=$subscriptionRepo->subs[$ids[0]][0];
try{$planner->plan('ci-tenant',[$ids[0]]);throw new RuntimeException('Over-capacity source admitted.');}
catch(DomainException $expected){}
array_pop($subscriptionRepo->subs[$ids[0]]);
$subscriptionRepo->subs[$ids[0]][0]=new MarketSubscription(
    MarketSubscriptionId::fromString('subscription:bad'),MarketSourceId::fromString($ids[0]),
    null,InstrumentId::fromString('instrument:ci:1'),MarketEventType::OrderBookDelta,
    MarketSubscriptionStatus::Active,$now
);
try{$planner->plan('ci-tenant',[$ids[0]]);throw new RuntimeException('L2 delta misrouted into BBO batch.');}
catch(DomainException $expected){}
echo "Multi-symbol control plane admitted 300 BBO over 12 source-fenced batches; limits and L2 isolation passed.\n";
