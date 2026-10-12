<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Contract\StreamingWorkerLeaseInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerTransportInterface;
use Domains\CapitalMarkets\Application\Service\StreamingWorkerRunner;
use Domains\CapitalMarkets\Application\Service\StreamingSessionCoordinator;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $reason):void{
    if(!$condition)throw new RuntimeException($reason);
};
$lease=new class implements StreamingWorkerLeaseInterface{
    public int $renewals=0;
    public int $releases=0;
    public bool $valid=true;
    public bool $failRenew=false;
    public function acquire(string $org,string $src,string $shard,string $worker,int $ttlSeconds=30):?int{return 7;}
    public function renew(string $org,string $src,string $shard,string $worker,int $token,int $ttlSeconds=30):bool{
        $this->renewals++;
        return !$this->failRenew && $this->valid && $token===7;
    }
    public function owns(string $org,string $src,string $shard,string $worker,int $token):bool{
        return $this->valid && $token===7;
    }
    public function release(string $org,string $src,string $shard,string $worker,int $token):void{
        $this->releases++;
    }
};
$invalidator=new class implements StreamingTrustInvalidatorInterface{
    /** @var list<string> */
    public array $reasons=[];
    public function invalidate(string $org,string $src,string $shard,string $worker,int $token,string $reason):int{
        $this->reasons[]=$reason;
        return 1;
    }
};
$session=new StreamingSessionCoordinator(new StreamingRecoveryGate(),
    new StreamingSubscriptionReconciler(),new StreamingHeartbeatWatchdog(3000,3000));
$transport=new class($session) implements StreamingWorkerTransportInterface {
    public int $opens=0;
    public int $subscribes=0;
    public int $pumps=0;
    public bool $stop=false;
    /** @var list<int> */
    public array $generations=[];
    public function __construct(private StreamingSessionCoordinator $session){}
    public function open(string $org,string $src,int $generation):void{
        $this->opens++;
        $this->generations[]=$generation;
        if($this->session->trusted())throw new RuntimeException('New socket incorrectly trusted.');
    }
    public function subscribe(array $keys,int $generation):void{
        if($keys!==['bookTicker'])throw new RuntimeException('Missing restored subscription.');
        $this->subscribes++;
        $this->session->subscribed($generation,'bookTicker');
        $this->session->verifiedSnapshot($generation,(string)$generation,true);
        $this->session->verifiedRecovery($generation,true,true);
    }
    public function pump(int $generation,int $timeoutMilliseconds):void{
        $this->pumps++;
        if($this->opens===1)throw new RuntimeException('Simulated WebSocket disconnect.');
        $this->stop=true;
    }
    public function close():void{}
};
$now=100;
$runner=new StreamingWorkerRunner($lease,$invalidator);
$status=$runner->run('org','source','shard','worker',['bookTicker'],
    $session,$transport,
    static function()use(&$now):int{return $now;},
    static fn():bool=>$transport->stop,
    static function(int $epoch):void{},
    2,50,20,null,new ReconnectPolicy(80,80,2),
    static function(int $milliseconds)use(&$now):void{$now+=$milliseconds;},
);
$assert($status==='STOPPED','Reconnect worker should stop on request: '.$status);
$assert($transport->opens===2,'Disconnected socket must reconnect.');
$assert($transport->subscribes===2,'Reconnection must re-subscribe.');
$assert($transport->generations[0]!==$transport->generations[1],'Reconnection must increment session generation.');
$assert(in_array('TRANSPORT_FAILURE',$invalidator->reasons,true),'Trust was not revoked on disconnect.');
$assert($invalidator->reasons[0]==='SESSION_RESTART','Initial persisted trust was not invalidated.');
$assert(!$session->trusted(),'Stopped worker must be untrusted.');
$assert($lease->releases===1,'Lease should be released exactly once.');

$lease->renewals=0;
$lease->failRenew=true;
$transport->stop=false;
$transport->opens=0;
$now=100;
$status=$runner->run('org','source','shard','worker',['bookTicker'],
    new StreamingSessionCoordinator(new StreamingRecoveryGate(),
        new StreamingSubscriptionReconciler(),new StreamingHeartbeatWatchdog(3000,3000)),
    new class implements StreamingWorkerTransportInterface{
        public function open(string $org,string $src,int $epoch):void{}
        public function subscribe(array $keys,int $epoch):void{}
        public function pump(int $epoch,int $time):void{throw new RuntimeException('offline');}
        public function close():void{}
    },
    static function()use(&$now):int{return $now;},static fn():bool=>false,
    static function(int $epoch):void{},2,50,20,null,
    new ReconnectPolicy(100,100,2),
    static function(int $milliseconds)use(&$now):void{$now+=$milliseconds;},
);
$assert($status==='LEASE_LOST','Lost lease during backoff must terminate.');
$assert($lease->renewals>=1,'Lease renewal was skipped during backoff.');

echo "Capital Markets reconnect worker passed.\n";
