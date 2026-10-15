<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface StreamingTrustInvalidatorInterface
{
    public function invalidate(string $organizationId,string $sourceId,string $shard,string $workerId,int $fencingToken,string $reason):int;
}
