<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

/** Operator execution; not the asynchronous worker envelope. */
final readonly class ProcessCrmInboxNowCommand implements CommandInterface
{
    public function __construct(public OrganizationId $organizationId,public int $actorId,
        public string $inboxId,public string $revision,public string $idempotencyKey,public string $correlationId) {}
}
