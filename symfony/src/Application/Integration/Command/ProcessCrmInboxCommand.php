<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class ProcessCrmInboxCommand implements CommandInterface
{
    public function __construct(
        public string $organizationId,
        public string $inboxId,
        public string $correlationId,
    ) {
    }
}
