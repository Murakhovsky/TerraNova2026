<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

interface GrowthEngagementDeliveryRepositoryInterface
{
    /** @param array<string,mixed> $observation @return array<string,mixed> */
    public function recordOrVerify(array $observation):array;

    /** @return array<string,mixed>|null */
    public function latestForExecution(string $organizationId,string $executionId):?array;

    /** @return list<array<string,mixed>> */
    public function forExecution(string $organizationId,string $executionId,int $limit=20):array;
}
