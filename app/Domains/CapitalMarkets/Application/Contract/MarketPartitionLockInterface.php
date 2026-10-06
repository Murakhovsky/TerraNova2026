<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

interface MarketPartitionLockInterface
{
    public function synchronized(string $partitionKey,callable $criticalSection):mixed;
}
