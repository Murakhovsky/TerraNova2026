<?php

declare(strict_types=1);

namespace App\Application\Security\Contract;

interface MutationLockManagerInterface
{
    public function synchronized(
        string $scope,
        string $resourceId,
        callable $criticalSection,
        int $timeoutSeconds = 5,
    ): mixed;
}
