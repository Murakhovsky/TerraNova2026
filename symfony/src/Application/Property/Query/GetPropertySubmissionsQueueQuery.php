<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetPropertySubmissionsQueueQuery implements QueryInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public string $status='',
        public int $page=1,
        public int $perPage=25,
    ) {
    }
}
