<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetPropertyInventoryCollectionQuery implements QueryInterface
{
    /** @param array<string,string> $filters */
    public function __construct(
        public OrganizationId $organizationId,
        public string $search = '',
        public array $filters = [],
        public int $page = 1,
        public int $perPage = 25,
    ) {
    }
}
