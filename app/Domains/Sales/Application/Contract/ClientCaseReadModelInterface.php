<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

interface ClientCaseReadModelInterface
{
    public function filters(array $query): array;
    public function cases(array $filters): array;
    public function stats(): array;
    public function case(int $id): ?array;
    public function inboundRequests(int $caseId): array;
    public function activities(int $caseId): array;
    public function propertyMatches(int $caseId): array;
    public function requestMatches(int $caseId): array;
    public function unlinkedInboundRequests(): array;
    public function inboundFilters(array $query): array;
    public function inboundInbox(array $filters): array;
    public function inboundInboxStats(): array;
    public function leadActivities(int $leadId): array;
    public function openCaseOptions(): array;
    public function managerOptions(): array;
}
