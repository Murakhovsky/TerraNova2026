<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\Sales\Query\GetSalesDashboardQuery;
use App\Application\Sales\Query\GetSalesLeadQuery;
use App\Application\Sales\Query\GetSalesOpportunityQuery;
use App\Application\Sales\Query\GetSalesPipelinesQuery;
use App\Application\Sales\Query\ListSalesLeadsQuery;
use App\Application\Sales\Query\ListSalesOpportunitiesQuery;
use Kernel\Application\Bus\QueryBusInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class SalesReadController
{
    public function __construct(
        private QueryBusInterface $queries,
        private TenantContextProviderInterface $tenants,
    ) {
    }

    public function dashboard(): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return $this->forbidden();

        $actor = $tenant->userId()->value();
        $ownerId = ctype_digit($actor) ? (int) $actor : null;

        return $this->ok($this->queries->ask(
            new GetSalesDashboardQuery($tenant->organizationId(), $ownerId),
        ));
    }

    public function leads(Request $request): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return $this->forbidden();

        return $this->ok($this->queries->ask(
            new ListSalesLeadsQuery($tenant->organizationId(), $request->query->all()),
        ));
    }

    public function lead(string $id): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return $this->forbidden();

        $data = $this->queries->ask(new GetSalesLeadQuery($tenant->organizationId(), (int) $id));
        return $data === null
            ? new JsonResponse(['ok' => false, 'error' => 'Lead not found.'], 404)
            : $this->ok($data);
    }

    public function opportunities(Request $request): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return $this->forbidden();

        return $this->ok($this->queries->ask(
            new ListSalesOpportunitiesQuery($tenant->organizationId(), $request->query->all()),
        ));
    }

    public function opportunity(string $id): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return $this->forbidden();

        $data = $this->queries->ask(new GetSalesOpportunityQuery($tenant->organizationId(), (int) $id));
        return $data === null
            ? new JsonResponse(['ok' => false, 'error' => 'Opportunity not found.'], 404)
            : $this->ok($data);
    }

    public function pipelines(): JsonResponse
    {
        $tenant = $this->tenants->current();
        if ($tenant === null) return $this->forbidden();

        return $this->ok($this->queries->ask(new GetSalesPipelinesQuery($tenant->organizationId())));
    }

    private function ok(mixed $data): JsonResponse
    {
        return new JsonResponse(['ok' => true, 'data' => $data]);
    }

    private function forbidden(): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => 'Tenant context required.'], 403);
    }
}
