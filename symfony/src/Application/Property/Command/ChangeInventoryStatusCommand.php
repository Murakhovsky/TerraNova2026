<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ChangeInventoryStatusCommand implements CommandInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $inventoryId,
        public string $status,
        public ?string $reason,
        public string $idempotencyKey,
    ) {}
}
