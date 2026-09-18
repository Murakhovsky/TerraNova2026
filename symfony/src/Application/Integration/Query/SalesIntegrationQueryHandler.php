<?php
declare(strict_types=1);

namespace App\Application\Integration\Query;

use DomainException;
use Domains\Sales\Application\Contract\SalesIntegrationAdministrationInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class SalesIntegrationQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SalesIntegrationAdministrationInterface $integrations)
    {
    }

    /** @return array<string,mixed>|array<int,mixed> */
    public function __invoke(SalesIntegrationQuery $query): array
    {
        $organizationId = $query->organizationId->value();

        return match ($query->operation) {
            SalesIntegrationQuery::CATALOG => $this->integrations->catalog(),
            SalesIntegrationQuery::LIST => $this->integrations->integrations($organizationId),
            SalesIntegrationQuery::ROUTING_OPTIONS => $this->integrations->routingOptions($organizationId),
            SalesIntegrationQuery::VIEW => $this->view($organizationId, $query->integrationId),
            SalesIntegrationQuery::REVISIONS => $this->revisions($organizationId, $query->integrationId, $query->limit),
            default => throw new DomainException('Unsupported Sales integration query.'),
        };
    }

    /** @return array<string,mixed> */
    private function view(string $organizationId, ?int $integrationId): array
    {
        if ($integrationId === null || $integrationId <= 0) {
            throw new DomainException('Invalid integration id.');
        }

        $integration = $this->integrations->integration($organizationId, $integrationId);
        if ($integration === null) {
            throw new DomainException('Sales integration was not found.');
        }

        return $integration;
    }

    /** @return array<int,mixed> */
    private function revisions(string $organizationId, ?int $integrationId, int $limit): array
    {
        if ($integrationId === null || $integrationId <= 0) {
            throw new DomainException('Invalid integration id.');
        }

        return $this->integrations->revisions($organizationId, $integrationId, max(1, min(200, $limit)));
    }
}
