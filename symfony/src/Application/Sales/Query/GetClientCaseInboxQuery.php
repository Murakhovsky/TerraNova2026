<?php

declare(strict_types=1);

namespace App\Application\Sales\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetClientCaseInboxQuery implements QueryInterface
{
    /** @param array<string,mixed> $filters */
    public function __construct(
        public OrganizationId $organizationId,
        public array $filters = [],
    ) {
    }
}
