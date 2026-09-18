<?php
declare(strict_types=1);

namespace App\Application\Sales\Admin;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SalesAdminQuery implements QueryInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organizationId,
        public string $operation,
        public ?string $resourceId = null,
        public array $input = [],
    ) {
    }
}
