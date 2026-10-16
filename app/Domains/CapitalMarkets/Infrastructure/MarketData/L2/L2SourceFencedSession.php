<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\StreamingWorkerLeaseInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingTrustInvalidatorInterface;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingFencedIngestionGateway;

/**
 * One independently fenced L2 source lease for each WebSocket/recovery epoch.
 * The existing venue-specific snapshot/delta/checksum processors remain the
 * only authorities for book reconstruction. No raw delta is ever trusted.
 */
final class L2SourceFencedSession
{
    private ?int $token=null;
    private ?int $epoch=null;
    private string $worker;
    private const TTL=60;

    public function __construct(
        private readonly StreamingWorkerLeaseInterface $leases,
        private readonly StreamingTrustInvalidatorInterface $invalidator,
        private readonly StreamingFencedIngestionGateway $gateway,
        private readonly string $organization,
        private readonly string $source,
        private readonly string $shard='l2-verified-book',
    ){
        foreach([$organization,$source,$shard] as $part){
            if($part===''||strlen($part)>190||trim($part)!==$part){
                throw new DomainException('L2 source fence identity invalid.');
            }
        }
        $this->worker='l2-'.bin2hex(random_bytes(12));
    }

    public function start(int $epoch):void
    {
        if($epoch<1||($this->epoch!==null&&$epoch<=$this->epoch)){
            throw new DomainException('Non-increasing L2 recovery epoch.');
        }
        if($this->token!==null)$this->disconnect('L2_EPOCH_REPLACED');
        $token=$this->leases->acquire(
            $this->organization,$this->source,$this->shard,$this->worker,self::TTL,
        );
        if($token===null)throw new DomainException('L2 source already owned by another streaming worker.');
        $this->token=$token;
        $this->epoch=$epoch;
        try{
            // Never inherit trust across independent sockets or provider epochs.
            $this->invalidator->invalidate(
                $this->organization,$this->source,$this->shard,$this->worker,$token,
                'L2_SESSION_RESTART',
            );
        }catch(\Throwable $failure){
            // Do not release on failed invalidation: original worker is fenced
            // by eventual TTL + expired-lease sweeper, not falsely clean.
            $this->token=null;
            throw $failure;
        }
    }

    public function publish(RawMarketEvent $event):MarketDataIngestionResult
    {
        $token=$this->token;
        if($token===null||$this->epoch===null||$event->sourceId->value()!==$this->source
            ||$event->eventType!=='l2.verified.snapshot'
            ||($event->transportMetadata['recovery']??null)!=='VERIFIED'){
            throw new DomainException('Unverified L2 or missing worker fence.');
        }
        if(!$this->leases->renew(
            $this->organization,$this->source,$this->shard,$this->worker,$token,self::TTL,
        )){
            $this->token=null;
            throw new DomainException('L2 lease lost before canonical publication.');
        }
        return $this->gateway->ingest(
            $this->organization,$this->source,$this->shard,$this->worker,$token,$event,
        );
    }

    public function disconnect(string $reason='L2_SOCKET_DISCONNECTED'):void
    {
        $token=$this->token;
        if($token===null)return;
        $this->token=null;
        $this->invalidator->invalidate(
            $this->organization,$this->source,$this->shard,$this->worker,$token,$reason,
        );
        $this->leases->release(
            $this->organization,$this->source,$this->shard,$this->worker,$token,
        );
    }

    public function active():bool{return $this->token!==null;}
}
