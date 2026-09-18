<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SalesFrontendMutationCommand implements CommandInterface
{
    public const QUICK_UPDATE = 'quick_update';
    public const OWNER = 'owner';
    public const MEETING = 'meeting';
    public const COMPLETE_ACTIVITY = 'complete_activity';
    public const RESCHEDULE_ACTIVITY = 'reschedule_activity';
    public const LEAD_FOLLOWUP = 'lead_followup';
    public const APPROVAL = 'approval';
    public const ACTION = 'action';

    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $operation,
        public string $resourceId,
        public array $input,
        public string $correlationId,
        public string $idempotencyKey,
    ) {
    }
}
