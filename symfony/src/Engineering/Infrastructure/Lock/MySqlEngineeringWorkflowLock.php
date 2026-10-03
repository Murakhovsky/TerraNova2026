<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Lock;

use App\Engineering\Application\Lock\EngineeringWorkflowLockInterface;
use App\Infrastructure\Concurrency\MySqlAdvisoryLock;

final readonly class MySqlEngineeringWorkflowLock implements EngineeringWorkflowLockInterface
{
    public function __construct(private MySqlAdvisoryLock $lock)
    {
    }

    public function synchronized(string $featureId, callable $criticalSection): mixed
    {
        return $this->lock->synchronized(
            'engineering:feature:'.$featureId.':workflow',
            $criticalSection,
            2,
        );
    }
}
