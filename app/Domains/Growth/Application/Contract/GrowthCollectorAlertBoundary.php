<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthCollectorAlertBoundary
{
    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createSubscription(
        string $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input
    ):array;

    /** @return array<string,mixed> */
    public function setEnabled(
        string $organizationId,int $actorId,string $correlationId,string $subscriptionId,bool $enabled,string $idempotencyKey
    ):array;

    /** @return list<array<string,mixed>> */
    public function subscriptions(string $organizationId):array;
}
