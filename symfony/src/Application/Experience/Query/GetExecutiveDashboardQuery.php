<?php

declare(strict_types=1);

namespace App\Application\Experience\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetExecutiveDashboardQuery implements QueryInterface
{
    public function __construct(public OrganizationId $organizationId)
    {
    }
}
