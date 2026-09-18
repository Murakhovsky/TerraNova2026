<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use DateTimeImmutable;
use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ScheduleSalesNextActionCommand implements CommandInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public int $opportunityId,
        public string $title,
        public ?string $body,
        public DateTimeImmutable $dueAt,
        public string $correlationId,
        public string $idempotencyKey,
    ) {
    }
}
