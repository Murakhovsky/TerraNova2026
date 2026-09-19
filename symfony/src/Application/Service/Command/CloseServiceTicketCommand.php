<?php
declare(strict_types=1);

namespace App\Application\Service\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class CloseServiceTicketCommand implements CommandInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $ticketId,
        public string $idempotencyKey,
    ) {}
}
