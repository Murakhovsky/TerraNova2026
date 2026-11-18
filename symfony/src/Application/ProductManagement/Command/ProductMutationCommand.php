<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

/** Trusted tenant/actor are injected by the canonical Capability Gateway. */
final readonly class ProductMutationCommand implements CommandInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organization,
        public string $operation,
        public array $input,
        public string $idempotencyKey,
        public string $actorId,
        public string $correlationId,
    ) {}
}
