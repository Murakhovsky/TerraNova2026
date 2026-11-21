<?php
declare(strict_types=1);

namespace App\Application\ProductManagement\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class ProductManagementQuery implements QueryInterface
{
    /** @param array<string,mixed> $input */
    public function __construct(
        public OrganizationId $organization,
        public string $operation,
        public array $input,
    ) {}
}
