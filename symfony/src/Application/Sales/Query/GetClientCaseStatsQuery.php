<?php
declare(strict_types=1);

namespace App\Application\Sales\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetClientCaseStatsQuery implements QueryInterface
{
    public function __construct(public OrganizationId $organizationId)
    {
    }
}
