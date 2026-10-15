<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerLeaseInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerTransportInterface;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use RuntimeException;
use Throwable;

/**
 * Long-lived, lease-fenced session supervisor. Every reconnect obtains a new
 * session generation and requires fresh subscriptions and provider recovery.
 * No network/ingestion callback can claim trust for the previous generation.
 *
 * The transport MUST feed validated protocol callbacks to the coordinator
 * and the canonical fenced ingestion gateway. This class never writes quotes.
 */
final readonly class StreamingWorkerRunner
{
    public function __construct(
        private StreamingWorkerLeaseInterface $leases,
        private StreamingTrustInvalidatorInterface $invalidator,
    ){}

    /**
     * @param list<string> $desiredKeys
     * @param callable():int $clockMs monotonic milliseconds
     * @param callable():bool $stopRequested
     * @param callable(int):void $onTick
     * @param null|callable(int):void $sleepMilliseconds injectable for tests
     * @return string STOPPED|LEASE_BUSY|LEASE_LOST|FAILED|INVALIDATION_FAILED|RECONNECT_EXHAUSTED
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
        ?ReconnectPolicy $reconnectPolicy=null,
        ?callable $sleepMilliseconds=null,
    ):string{
        if($leaseSeconds<2||$renewMilliseconds<50
            ||$renewMilliseconds>$leaseSeconds*500
            ||$pollMilliseconds<1||$pollMilliseconds>$renewMilliseconds){
            throw new DomainException('Unsafe streaming worker renewal configuration.');
        }
        $policy=$reconnectPolicy??new ReconnectPolicy();
        $sleepMilliseconds??=static function(int $milliseconds):void{
            usleep($milliseconds*1000);
        };

        $token=$this->leases->acquire($organizationId,$sourceId,$shard,$workerId,$leaseSeconds);
        if($token===null)return 'LEASE_BUSY';

        $generation=null;
        $owned=true;
        $invalidationFailed=false;
        $status='STOPPED';
        $reason='WORKER_STOP';
        $lastRenew=(int)$clockMs();
        $attempt=0;

        $renewLease=function() use(
            $clockMs,&$lastRenew,$renewMilliseconds,
            $organizationId,$sourceId,$shard,$workerId,$token,$leaseSeconds
        ):void{
            $now=(int)$clockMs();
            if($now<$lastRenew)throw new DomainException('Monotonic clock regressed.');
            if($now-$lastRenew<$renewMilliseconds)return;
            if(!$this->leases->renew($organizationId,$sourceId,$shard,$workerId,$token,$leaseSeconds)){
                throw new DomainException('LEASE_LOST');
            }
            $lastRenew=$now;
        };

        $invalidate=function(string $failure) use (
            $organizationId,$sourceId,$shard,$workerId,$token
        ):void{
            if(!$this->leases->owns($organizationId,$sourceId,$shard,$workerId,$token)){
                throw new DomainException('LEASE_LOST');
            }
            $this->invalidator->invalidate($organizationId,$sourceId,$shard,$workerId,$token,$failure);
        };

        try{
            if($onLeaseAcquired!==null)$onLeaseAcquired($token);
            $invalidate('SESSION_RESTART');

            while(!$stopRequested()){
                $generation=null;
                $retry=false;
                $reason='WORKER_STOP';
                try{
                    $renewLease();
                    $generation=$session->start($desiredKeys,(int)$clockMs());
                    $transport->open($organizationId,$sourceId,$generation);
                    // TLS handshake can block: renew/verify before subscribing.
                    $renewLease();
                    $session->socketConnected($generation);
                    $transport->subscribe($session->pending(),$generation);

                    while(!$stopRequested()){
                        $renewLease();
                        $transport->pump($generation,$pollMilliseconds);
                        $renewLease();
                        $onTick($generation);
                        if($session->status()==='UNTRUSTED'){
                            $reason=$session->reason()??'INTEGRITY_LOST';
                            $retry=true;
                            break;
                        }
                        if($session->status()==='DISABLED'){
                            throw new DomainException('Streaming session disabled.');
                        }
                    }
                }catch(DomainException $error){
                    // Bad configuration/integrity evidence and DB fencing
                    // errors must not be hidden by an endless reconnect loop.
                    $reason=$error->getMessage()==='LEASE_LOST'?'LEASE_LOST':'SESSION_REJECTED';
                    $status=$reason==='LEASE_LOST'?'LEASE_LOST':'FAILED';
                    if($reason==='LEASE_LOST')$owned=false;
                }catch(RuntimeException){
                    // Network failures (including WS protocol framing errors)
                    // revoke trust before the next connection attempt.
                    $reason='TRANSPORT_FAILURE';
                    $retry=true;
                }catch(Throwable){
                    $reason='WORKER_FAILURE';
                    $status='FAILED';
                }finally{
                    try{$transport->close();}catch(Throwable){}
                    if($generation!==null){
                        try{$session->disconnected($generation,$reason);}catch(Throwable){}
                    }
                }

                if($owned){
                    try{$invalidate($reason);$invalidationFailed=false;}
                    catch(DomainException $error){
                        if($error->getMessage()==='LEASE_LOST'){
                            $owned=false;$status='LEASE_LOST';
                        }else {$status='INVALIDATION_FAILED';$invalidationFailed=true;}
                        $retry=false;
                    }catch(Throwable){
                        $status='INVALIDATION_FAILED';$invalidationFailed=true;
                        $retry=false;
                    }
                }
                if(!$owned||!$retry||$stopRequested())break;

                $attempt++;
                if(!$policy->allowsAttempt($attempt)){
                    $status='RECONNECT_EXHAUSTED';
                    break;
                }
                // Half jitter avoids reconnect storms but retains a bounded
                // delay. Time spent backing off still renews the same lease.
                $maxDelay=$policy->delayForAttempt($attempt);
                $delay=intdiv($maxDelay,2)+random_int(0,$maxDelay-intdiv($maxDelay,2));
                $deadline=(int)$clockMs()+$delay;
                try{
                    while(!$stopRequested() && (int)$clockMs()<$deadline){
                        $renewLease();
                        $left=max(0,$deadline-(int)$clockMs());
                        $chunk=min($pollMilliseconds,$left);
                        if($chunk>0)$sleepMilliseconds($chunk);
                    }
                    $renewLease();
                }catch(DomainException $error){
                    $status=$error->getMessage()==='LEASE_LOST'?'LEASE_LOST':'FAILED';
                    if($status==='LEASE_LOST')$owned=false;
                    break;
                }catch(Throwable){
                    $status='FAILED';
                    break;
                }
            }
        }catch(DomainException $error){
            $status=$error->getMessage()==='LEASE_LOST'?'LEASE_LOST':'FAILED';
            if($status==='LEASE_LOST')$owned=false;
        }catch(Throwable){
            $status='FAILED';
        }finally{
            try{$transport->close();}catch(Throwable){}
            if($owned){
                try{$invalidate($reason);$invalidationFailed=false;}
                catch(DomainException $error){
                    if($error->getMessage()==='LEASE_LOST'){
                        $owned=false;$status='LEASE_LOST';
                    }else {$status='INVALIDATION_FAILED';$invalidationFailed=true;}
                }catch(Throwable){$status='INVALIDATION_FAILED';$invalidationFailed=true;}
            }
            // Preserve an unexpired owner after a failed revocation. If the
            // worker cannot invalidate, the independent expiry sweeper must
            // still have a recoverable owner row rather than an empty lease.
            if(!$invalidationFailed)try{$this->leases->release($organizationId,$sourceId,$shard,$workerId,$token);}catch(Throwable){}
        }
        return $status;
    }
}
