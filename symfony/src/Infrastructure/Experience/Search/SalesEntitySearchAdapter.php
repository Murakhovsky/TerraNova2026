<?php

declare(strict_types=1);

namespace App\Infrastructure\Experience\Search;

use App\Application\Experience\Search\Contract\SalesEntitySearchInterface;
use App\Application\Experience\Search\EntitySearchHit;
use App\Security\SalesRequestScope;
use DomainException;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;

final readonly class SalesEntitySearchAdapter implements SalesEntitySearchInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales,private SalesRequestScope $scope)
    {
    }

    public function search(string $organizationId, string $query, int $limit = 10): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));

        if ($query === '') {
            return [];
        }

        try {$owner=$this->scope->searchOwner($organizationId);}
        catch (DomainException) {return [];}
        $filters=['q'=>$query,'limit'=>$limit,'owner_id'=>$owner];
        $items = [];

        foreach ($this->sales->deals($organizationId, $filters) as $deal) {
            $id = (int) ($deal['id'] ?? 0);
            if ($id <= 0 || !$this->scope->entity($organizationId, 'deal', $id)) {
                continue;
            }

            $title = trim((string) ($deal['title'] ?? ''));
            $publicId = trim((string) ($deal['public_id'] ?? ''));
            $customer = trim((string) ($deal['customer'] ?? ''));
            $stage = trim((string) ($deal['stage_name'] ?? $deal['stage_code'] ?? ''));

            $items[] = new EntitySearchHit(
                id: 'sales.deal.' . $id,
                label: $title !== '' ? $title : ($publicId !== '' ? $publicId : 'Deal #' . $id),
                path: '/sales/deals/' . $id,
                entityType: 'sales.deal',
                entityId: (string) $id,
                subtitle: implode(' · ', array_values(array_filter([$publicId, $customer, $stage]))),
                score: 92.0,
            );
        }

        foreach ($this->sales->leads($organizationId, $filters) as $lead) {
            $id = (int) ($lead['id'] ?? 0);
            if ($id <= 0 || !$this->scope->entity($organizationId, 'lead', $id)) {
                continue;
            }

            $name = trim((string) ($lead['name'] ?? ''));
            $source = trim((string) ($lead['source'] ?? ''));
            $status = trim((string) ($lead['status'] ?? ''));

            $items[] = new EntitySearchHit(
                id: 'sales.lead.' . $id,
                label: $name !== '' ? $name : 'Lead #' . $id,
                path: '/sales/leads?q=' . rawurlencode($name !== '' ? $name : (string) $id),
                entityType: 'sales.lead',
                entityId: (string) $id,
                subtitle: implode(' · ', array_values(array_filter(['Lead #' . $id, $source, $status]))),
                score: 90.0,
            );
        }

        return array_slice($items, 0, $limit);
    }
}
