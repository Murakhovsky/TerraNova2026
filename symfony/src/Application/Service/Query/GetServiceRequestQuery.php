<?php
declare(strict_types=1);

namespace App\Application\Service\Query;

use Kernel\Application\Query\QueryInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class GetServiceRequestQuery implements QueryInterface
{
    public function __construct(public OrganizationId $organizationId, public string $requestId) {}
}
