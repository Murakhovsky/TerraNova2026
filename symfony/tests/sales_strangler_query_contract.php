<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\Sales\Query\GetClientCaseStatsQuery;
use App\Application\Sales\Query\GetClientCaseStatsQueryHandler;
use Domains\Sales\Application\Contract\ClientCaseReadModelFactoryInterface;
use Domains\Sales\Application\Contract\ClientCaseReadModelInterface;
use Kernel\Shared\Domain\OrganizationId;

function expectSalesSlice(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$factory = new class implements ClientCaseReadModelFactoryInterface {
    public ?string $organizationId = null;

    public function forOrganization(string $organizationId): ClientCaseReadModelInterface
    {
        $this->organizationId = $organizationId;

        return new class implements ClientCaseReadModelInterface {
            public function filters(array $query): array { return []; }
            public function cases(array $filters): array { return []; }
            public function stats(): array { return ['all' => 2, 'NEW' => 1, 'WON' => 1]; }
            public function case(int $id): ?array { return null; }
            public function inboundRequests(int $caseId): array { return []; }
            public function activities(int $caseId): array { return []; }
            public function propertyMatches(int $caseId): array { return []; }
            public function requestMatches(int $caseId): array { return []; }
            public function unlinkedInboundRequests(): array { return []; }
            public function inboundFilters(array $query): array { return []; }
            public function inboundInbox(array $filters): array { return []; }
            public function inboundInboxStats(): array { return []; }
            public function leadActivities(int $leadId): array { return []; }
            public function openCaseOptions(): array { return []; }
            public function managerOptions(): array { return []; }
        };
    }
};

$result = (new GetClientCaseStatsQueryHandler($factory))(
    new GetClientCaseStatsQuery(OrganizationId::fromString('org-sales')),
);

expectSalesSlice($factory->organizationId === 'org-sales', 'Query handler must pass explicit tenant scope to the read-model factory.');
expectSalesSlice($result === [
    'organization_id' => 'org-sales',
    'stats' => ['all' => 2, 'NEW' => 1, 'WON' => 1],
], 'Query handler must preserve the tenant-scoped Sales stats contract.');

echo "Symfony Sales strangler query contract passed.\n";
