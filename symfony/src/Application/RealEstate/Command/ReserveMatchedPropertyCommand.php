<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ReserveMatchedPropertyCommand implements CommandInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $correlationId,
        public string $caseId,
        public string $idempotencyKey,
        public array $input,
    ) {}
}
