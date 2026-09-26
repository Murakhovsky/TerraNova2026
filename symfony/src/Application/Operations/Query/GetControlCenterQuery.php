<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetControlCenterQuery implements QueryInterface
{
    public function __construct(
        public OrganizationId $organizationId,
        public int $limit = 30,
    ) {
    }
}
