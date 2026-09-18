<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\Sales\Query\GetSalesDashboardQuery;
use App\Application\Sales\Query\GetSalesDashboardQueryHandler;
use App\Application\Sales\Query\GetSalesLeadQuery;
use App\Application\Sales\Query\GetSalesLeadQueryHandler;
use App\Application\Sales\Query\GetSalesOpportunityQuery;
use App\Application\Sales\Query\GetSalesOpportunityQueryHandler;
use App\Application\Sales\Query\GetSalesPipelinesQuery;
use App\Application\Sales\Query\GetSalesPipelinesQueryHandler;
use App\Application\Sales\Query\ListSalesLeadsQuery;
use App\Application\Sales\Query\ListSalesLeadsQueryHandler;
use App\Application\Sales\Query\ListSalesOpportunitiesQuery;
use App\Application\Sales\Query\ListSalesOpportunitiesQueryHandler;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;
use Kernel\Shared\Domain\OrganizationId;

function expectWave1(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$read = new class implements SalesWorkspaceReadModelInterface {
    public array $calls = [];

    public function dashboard(string $organizationId, ?int $ownerId = null): array
    {
        $this->calls[] = ['dashboard', $organizationId, $ownerId];
        return ['organization' => $organizationId, 'owner' => $ownerId];
    }

    public function leads(string $organizationId, array $filters = []): array
    {
        $this->calls[] = ['leads', $organizationId, $filters];
        $count = (int) ($filters['limit'] ?? 1);
        return array_map(static fn (int $i): array => ['id' => $i + 1], range(0, max(0, $count - 1)));
    }

    public function lead(string $organizationId, int $leadId): ?array
    {
        $this->calls[] = ['lead', $organizationId, $leadId];
        return $leadId === 404 ? null : ['id' => $leadId, 'organization_id' => $organizationId];
    }

    public function deals(string $organizationId, array $filters = []): array
    {
        $this->calls[] = ['deals', $organizationId, $filters];
        $count = (int) ($filters['limit'] ?? 1);
        return array_map(static fn (int $i): array => ['id' => $i + 101], range(0, max(0, $count - 1)));
    }

    public function deal(string $organizationId, int $dealId): ?array
    {
        $this->calls[] = ['deal', $organizationId, $dealId];
        return $dealId === 404 ? null : ['id' => $dealId, 'organization_id' => $organizationId];
    }

    public function timeline(string $organizationId, int $dealId, int $limit = 100): array
    {
        $this->calls[] = ['timeline', $organizationId, $dealId, $limit];
        return [['id' => 'event-1']];
    }

    public function pipelines(string $organizationId): array
    {
        $this->calls[] = ['pipelines', $organizationId];
        return [['id' => 1, 'organization_id' => $organizationId]];
    }

    public function today(string $organizationId, int $ownerId): array { return []; }
    public function metrics(string $organizationId, int $days = 30): array { return []; }
};

$org = OrganizationId::fromString('tenant-wave1');

$dashboard = (new GetSalesDashboardQueryHandler($read))(new GetSalesDashboardQuery($org, 77));
expectWave1($dashboard['organization'] === 'tenant-wave1' && $dashboard['owner'] === 77, 'Dashboard must preserve explicit tenant and actor scope.');

$leads = (new ListSalesLeadsQueryHandler($read))(new ListSalesLeadsQuery($org, [
    'page' => 2, 'per_page' => 2, 'status' => 'new', 'sort' => 'name', 'direction' => 'asc', 'ignored' => 'drop-me',
]));
expectWave1(count($leads['items']) === 2 && $leads['pagination']['has_more'] === true, 'Lead pagination must use per_page + 1.');
$leadCall = array_values(array_filter($read->calls, static fn (array $call): bool => $call[0] === 'leads'))[0];
expectWave1(($leadCall[2]['offset'] ?? null) === 2 && ($leadCall[2]['limit'] ?? null) === 3, 'Lead pagination must translate page to limit/offset.');
expectWave1(!array_key_exists('ignored', $leadCall[2]), 'Lead query must drop unsupported filters.');

$lead = (new GetSalesLeadQueryHandler($read))(new GetSalesLeadQuery($org, 301));
expectWave1(($lead['organization_id'] ?? null) === 'tenant-wave1', 'Lead detail must remain tenant scoped.');
expectWave1((new GetSalesLeadQueryHandler($read))(new GetSalesLeadQuery($org, 404)) === null, 'Missing lead must stay null for the transport 404 mapping.');

$opportunities = (new ListSalesOpportunitiesQueryHandler($read))(new ListSalesOpportunitiesQuery($org, [
    'page' => 1, 'per_page' => 1, 'risk' => 'high', 'sort' => 'value',
]));
expectWave1(count($opportunities['items']) === 1 && $opportunities['pagination']['has_more'] === true, 'Opportunity pagination must use per_page + 1.');

$workspace = (new GetSalesOpportunityQueryHandler($read))(new GetSalesOpportunityQuery($org, 101));
expectWave1(($workspace['opportunity']['id'] ?? null) === 101 && count($workspace['timeline'] ?? []) === 1, 'Opportunity workspace must combine deal and timeline reads.');
expectWave1((new GetSalesOpportunityQueryHandler($read))(new GetSalesOpportunityQuery($org, 404)) === null, 'Missing opportunity must stay null for the transport 404 mapping.');

$pipelines = (new GetSalesPipelinesQueryHandler($read))(new GetSalesPipelinesQuery($org));
expectWave1(($pipelines[0]['organization_id'] ?? null) === 'tenant-wave1', 'Pipelines must remain tenant scoped.');

echo "Symfony Sales Wave 1 application contract passed.\n";
