<?php

declare(strict_types=1);

namespace App\Application\Experience\Query;

use Domains\Property\Application\Contract\PropertyFunnelAnalyticsInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetWorkspaceAnalyticsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PropertyFunnelAnalyticsInterface $analytics)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetWorkspaceAnalyticsQuery $query): array
    {
        return $this->analytics->report(max(7, min(365, $query->days)));
    }
}
