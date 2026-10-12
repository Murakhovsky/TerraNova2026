<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerLeaseInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerTransportInterface;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Throwable;

/**
 * Bounded supervisor invocation with leased ownership and safe shutdown.
 * Monotonic milliseconds are injected to support deterministic tests.
 *
 * The runner NEVER directly publishes MarketState. Its transport must use
 * StreamingFencedStateWriter and verify provider-specific integrity.
 */
final readonly class StreamingWorkerRunner
{
    public function __construct(
        private StreamingWorkerLeaseInterface $leases,
        private StreamingTrustInvalidatorInterface $invalidator,
    ){}

    /**
     * @param list<string> $desiredKeys
     * @param callable():int $clockMs monotonic clock
     * @param callable():bool $stopRequested
     * @param callable(int):void $onTick generation callback: heartbeat/quality
     * @return string terminal status
     */
    public function run(
        string $organizationId,string $sourceId,string $shard,string $workerId,
        array $desiredKeys,
        StreamingSessionCoordinator $session,
        StreamingWorkerTransportInterface $transport,
        callable $clockMs,
        callable $stopRequested,
        callable $onTick,
        int $leaseSeconds=30,int $renewMilliseconds=5000,int $pollMilliseconds=200,
        ?callable $onLeaseAcquired=null,
    ):string{
        if($renewMilliseconds<50||$renewMilliseconds>($leaseSeconds*1000/2) ||
           $pollMilliseconds<1||$pollMilliseconds>$renewMilliseconds){
            throw new DomainException('Unsafe streaming worker renewal configuration.');
        }
        $token=$this->leases->acquire($organizationId,$sourceId,$shard,$workerId,$leaseSeconds);
        if($token===null)return 'LEASE_BUSY';
        $generation=null;
        $reason='WORKER_STOP';
        $status='STOPPED';
        $owned=true;
        try{
            if($onLeaseAcquired!==null)$onLeaseAcquired($token);
            // A worker restart begins with stale data: never implicitly trust
            // retained current state before the new snapshot is validated.
            $this->invalidator->invalidate($organizationId,$sourceId,$shard,$workerId,$token,'SESSION_RESTART');
            $now=$clockMs();
            $generation=$session->start($desiredKeys,$now);
            $transport->open($organizationId,$sourceId,$generation);
            $session->socketConnected($generation);
            $transport->subscribe($session->pending(),$generation);

            $lastRenew=$now;
            while(!$stopRequested()){
                $transport->pump($generation,$pollMilliseconds);
                $now=$clockMs();
                if($now<$lastRenew)throw new DomainException('Monotonic clock regressed.');
                if($now-$lastRenew >= $renewMilliseconds){
                    if(!$this->leases->renew($organizationId,$sourceId,$shard,$workerId,$token,$leaseSeconds)){
                        $owned=false;
                        $reason='LEASE_LOST';
                        $status='LEASE_LOST';
                        break;
                    }
                    $lastRenew=$now;
                }
                $onTick($generation);
                // No trusted state publication here; orchestration/adapter
                // callbacks are responsible for integrity and heartbeat.
                if(in_array($session->status(),['UNTRUSTED','DISABLED'],true)){
                    $reason=$session->reason()??'UNTRUSTED';
                    $status='DEGRADED';
                    break;
                }
            }
        }catch(Throwable $error){
            $reason='WORKER_FAILURE';
            $status='FAILED';
            // Deliberately avoid leaking provider/secret data in status.
        }finally{
            try{$transport->close();}catch(Throwable){}
            if($generation!==null){
                try{$session->disconnected($generation,$reason);}catch(Throwable){}
            }
            if($owned){
                try{
                    if($this->leases->owns($organizationId,$sourceId,$shard,$workerId,$token)){
                        $this->invalidator->invalidate($organizationId,$sourceId,$shard,$workerId,$token,$reason);
                    }
                }catch(Throwable){$status='INVALIDATION_FAILED';}
            }
            try{$this->leases->release($organizationId,$sourceId,$shard,$workerId,$token);}catch(Throwable){}
        }
        return $status;
    }
}
