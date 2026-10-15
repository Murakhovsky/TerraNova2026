<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DomainException;

/**
 * Pure desired-vs-acknowledged subscription reconciliation.
 * The desired keys must originate from the tenant-scoped persistent
 * MarketSubscriptionRepository. A new connection generation has no ACKs.
 */
final class StreamingSubscriptionReconciler
{
    /** @var array<string,true> */
    private array $desired=[];
    /** @var array<string,true> */
    private array $acknowledged=[];
    private int $generation=0;

    /** @param list<string> $keys */
    public function setDesired(array $keys):void
    {
        $next=[];
        foreach($keys as $key){
            if($key===''||strlen($key)>400)throw new DomainException('Invalid stream subscription key.');
            $next[$key]=true;
        }
        $this->desired=$next;
    }

    public function resetForGeneration(int $generation):void
    {
        if($generation<1||$generation<=$this->generation)throw new DomainException('Subscription generation must advance.');
        $this->generation=$generation;
        $this->acknowledged=[];
    }

    public function acknowledge(int $generation,string $key):void
    {
        $this->assertGeneration($generation);
        if(!isset($this->desired[$key]))throw new DomainException('Cannot acknowledge an undesired subscription.');
        $this->acknowledged[$key]=true;
    }

    public function removeAcknowledgement(int $generation,string $key):void
    {
        $this->assertGeneration($generation);
        unset($this->acknowledged[$key]);
    }

    /** @return list<string> */
    public function toSubscribe():array
    {
        return array_keys(array_diff_key($this->desired,$this->acknowledged));
    }

    /** @return list<string> */
    public function toUnsubscribe():array
    {
        return array_keys(array_diff_key($this->acknowledged,$this->desired));
    }

    public function allAcknowledged():bool
    {
        return $this->generation>0 && $this->toSubscribe()===[];
    }

    private function assertGeneration(int $generation):void
    {
        if($generation!==$this->generation||$generation<1)throw new DomainException('Stale subscription acknowledgement.');
    }
}
