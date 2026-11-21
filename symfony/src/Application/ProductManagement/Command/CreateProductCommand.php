<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class CreateProductCommand implements CommandInterface
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public OrganizationId $organization,
        public array $data,
        public string $idempotencyKey,
        public string $actorId,
        public string $correlationId,
    ) {}
}
