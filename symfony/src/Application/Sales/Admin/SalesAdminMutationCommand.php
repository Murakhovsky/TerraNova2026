<?php
declare(strict_types=1);

namespace App\Application\Sales\Admin;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SalesAdminMutationCommand implements CommandInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public string $operation,
        public ?string $resourceId,
        public array $input,
        public string $correlationId,
    ) {
    }
}
