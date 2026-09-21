<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class RetryAsyncOperationCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $actorId,
        public string $operationId,
    ) {
    }
}
