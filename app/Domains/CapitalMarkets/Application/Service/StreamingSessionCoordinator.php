<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;

/**
 * Fail-closed orchestration of one provider/source/channel session.
 *
 * This is a synchronous and side-effect-free coordinator. A worker is
 * responsible for network I/O, durable leases, persisted state invalidation
 * and provider-specific continuity validation before passing true evidence.
 * Returning trusted() MUST NOT, by itself, publish a trusted MarketState.
 */
final class StreamingSessionCoordinator
{
    public function __construct(
        private readonly StreamingRecoveryGate $gate,
        private readonly StreamingSubscriptionReconciler $subscriptions,
        private readonly StreamingHeartbeatWatchdog $heartbeat,
    ){}

    /** @param list<string> $desired */
    public function start(array $desired,int $nowMs):int
    {
        $this->gate->disable(); // Revoke trust before beginning another session.
        $this->subscriptions->setDesired($desired);
        $epoch=$this->gate->start();
        $this->subscriptions->resetForGeneration($epoch);
        $this->heartbeat->begin($nowMs);
        return $epoch;
    }

    public function socketConnected(int $epoch):void
    {
        $this->gate->connected($epoch);
    }

    /** @return list<string> */
    public function pending():array
    {
        return $this->subscriptions->toSubscribe();
    }

    public function subscribed(int $epoch,string $key):void
    {
        $this->assertEpoch($epoch);
        $this->subscriptions->acknowledge($epoch,$key);
        if($this->subscriptions->allAcknowledged() && $this->gate->state()==='SUBSCRIBING'){
            $this->gate->acknowledged($epoch);
        }
    }

    public function subscriptionRejected(int $epoch,string $key):void
    {
        $this->assertEpoch($epoch);
        $this->gate->invalidate('SUBSCRIPTION_REJECTED');
    }

    public function verifiedSnapshot(int $epoch,?string $sequence,bool $providerVerified):void
    {
        $this->gate->snapshot($epoch,$sequence,$providerVerified);
    }

    public function verifiedRecovery(int $epoch,bool $continuityVerified,bool $freshAndValid):void
    {
        $this->gate->recovered($epoch,$continuityVerified,$freshAndValid);
    }

    public function verifiedDelta(int $epoch,?string $sequence,bool $continuityVerified,bool $freshAndValid):void
    {
        $this->gate->delta($epoch,$sequence,$continuityVerified,$freshAndValid);
    }

    public function pong(int $epoch,int $nowMs):void
    {
        $this->assertEpoch($epoch);
        $this->heartbeat->pong($nowMs);
    }

    public function message(int $epoch,int $nowMs):void
    {
        $this->assertEpoch($epoch);
        $this->heartbeat->message($nowMs);
    }

    /**
     * $expectMarketMessages is calculated from the exchange trading session,
     * not from the mere fact that the TCP socket remains open.
     */
    public function tick(int $epoch,int $nowMs,bool $expectMarketMessages):?string
    {
        $this->assertEpoch($epoch);
        $reason=$this->heartbeat->failureReason($nowMs,$expectMarketMessages);
        if($reason!==null){
            $this->gate->invalidate($reason);
        }
        return $reason;
    }

    public function disconnected(int $epoch,string $reason='DISCONNECTED'):void
    {
        $this->gate->disconnect($epoch,$reason);
    }

    public function trusted():bool
    {
        return $this->gate->trusted() && $this->subscriptions->allAcknowledged();
    }

    public function status():string { return $this->gate->state(); }
    public function reason():?string { return $this->gate->reason(); }

    private function assertEpoch(int $epoch):void
    {
        if($epoch!==$this->gate->generation()){
            throw new DomainException('Stale streaming coordinator epoch.');
        }
    }
}
