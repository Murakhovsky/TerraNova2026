<?php

declare(strict_types=1);

namespace App\Application\Content\Query;

use Domains\Content\Application\Contract\ContentServiceInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetContentAdministrationQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ContentServiceInterface $content)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetContentAdministrationQuery $query): array
    {
        return [
            'items' => $this->content->adminItems($query->filters),
            'stats' => $this->content->stats(),
            'integration_stats' => $this->content->integrationStats(),
            'deliveries' => $this->content->recentWebhookDeliveries(),
            'filters' => $query->filters,
        ];
    }
}
