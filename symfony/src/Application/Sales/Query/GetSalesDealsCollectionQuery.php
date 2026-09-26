<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetSalesDealsCollectionQuery implements QueryInterface
{
    /**
     * @param array<string,string> $filters
     */
    public function __construct(
        public OrganizationId $organizationId,
        public string $search,
        public array $filters,
        public int $page,
        public int $perPage,
    ) {
    }
}
