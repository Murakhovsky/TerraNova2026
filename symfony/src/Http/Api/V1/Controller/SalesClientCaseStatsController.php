<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Sales\Query\GetClientCaseStatsQuery;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class SalesClientCaseStatsController
{
    public function __construct(
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) {
            return new JsonResponse(['ok' => false, 'error' => 'Tenant context required.'], 403);
        }

        return new JsonResponse([
            'ok' => true,
            'data' => $this->queries->ask(new GetClientCaseStatsQuery($tenant->organizationId())),
        ]);
    }
}
