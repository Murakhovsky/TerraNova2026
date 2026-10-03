<?php
declare(strict_types=1);

namespace App\Engineering\Application\Lock;

interface EngineeringWorkflowLockInterface
{
    public function synchronized(string $featureId, callable $criticalSection): mixed;
}
