<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\GrowthCollectorAlertSubscription;

interface GrowthCollectorAlertSubscriptionRepositoryInterface
{
    public function create(GrowthCollectorAlertSubscription $subscription,int $actorId):void;

    public function lock(string $organizationId,string $subscriptionId):GrowthCollectorAlertSubscription;

    public function update(GrowthCollectorAlertSubscription $subscription,int $actorId):void;

    /** @return array<string,mixed>|null */
    public function view(string $organizationId,string $subscriptionId):?array;

    /** @return array<string,mixed>|null */
    public function findByEmail(string $organizationId,string $recipientEmail):?array;

    /** @return list<array<string,mixed>> */
    public function listAll(string $organizationId,int $limit=100):array;

    /** @return list<array<string,mixed>> */
    public function listEnabled(string $organizationId,int $limit=100):array;
}
