<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class UpdateSalesLeadCommand implements CommandInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public int $leadId,
        public string $correlationId,
        public array $input,
    ) {
    }
}
