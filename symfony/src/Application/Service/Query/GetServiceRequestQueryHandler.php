<?php
declare(strict_types=1);

namespace App\Application\Service\Query;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetServiceRequestQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(GetServiceRequestQuery $query):?array
    {
        return $this->service->viewRequest($query->organizationId->value(),$query->requestId);
    }
}
