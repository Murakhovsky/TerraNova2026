<?php
declare(strict_types=1);

namespace App\Infrastructure\CapitalMarkets;

use App\Infrastructure\Concurrency\MySqlAdvisoryLock;
use Domains\CapitalMarkets\Application\Contract\MarketPartitionLockInterface;

final readonly class MySqlMarketPartitionLock implements MarketPartitionLockInterface
{
    public function __construct(private MySqlAdvisoryLock $locks){}

    public function synchronized(string $partitionKey,callable $criticalSection):mixed
    {
        return $this->locks->synchronized(
            'capital-markets:market-state:'.hash('sha256',$partitionKey),
            $criticalSection,
            2,
        );
    }
}
