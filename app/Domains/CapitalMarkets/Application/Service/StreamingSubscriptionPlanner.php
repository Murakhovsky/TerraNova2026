<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Service;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;

/**
 * Read-only, tenant-scoped desired-subscription projection.
 *
 * No socket is opened here. A worker must resolve provider instruments,
 * hold a durable lease and obtain real provider ACKs before activation.
 */
final readonly class StreamingSubscriptionPlanner
{
    public function __construct(
        private MarketSourceRepositoryInterface $sources,
        private MarketSubscriptionRepositoryInterface $subscriptions,
    ){}

    /** @return array<string,MarketSubscription> Stable key => persisted intent */
    public function desired(string $organizationId,MarketSourceId $sourceId):array
    {
        if(trim($organizationId)==='')throw new DomainException('Organization identity is required.');
        $source=$this->sources->get($organizationId,$sourceId);
        if($source===null||!$source->enabled)return [];

        $desired=[];
        foreach($this->subscriptions->forSource($organizationId,$sourceId) as $subscription){
            if(!$subscription->sourceId->equals($sourceId)){
                throw new DomainException('Subscription repository returned a different source.');
            }
            if($subscription->status!==MarketSubscriptionStatus::Active)continue;
            $key=self::key($subscription);
            if(isset($desired[$key])){
                throw new DomainException('Duplicate active streaming subscription intent.');
            }
            $desired[$key]=$subscription;
        }
        ksort($desired,SORT_STRING);
        return $desired;
    }

    public static function key(MarketSubscription $subscription):string
    {
        return implode('|',[
            $subscription->sourceId->value(),
            $subscription->venueId?->value()??'REFERENCE',
            $subscription->instrumentId->value(),
            $subscription->dataType->value,
        ]);
    }
}
