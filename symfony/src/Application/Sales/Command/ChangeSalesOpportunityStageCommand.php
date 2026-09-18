<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ChangeSalesOpportunityStageCommand implements CommandInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public int $opportunityId,
        public string $targetStageId,
        public string $correlationId,
        public ?string $lostReasonId = null,
        public ?string $lostReasonNote = null,
    ) {
    }
}
