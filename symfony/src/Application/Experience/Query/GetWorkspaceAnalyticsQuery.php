<?php

declare(strict_types=1);

namespace App\Application\Experience\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetWorkspaceAnalyticsQuery implements QueryInterface
{
    public function __construct(public int $days = 30)
    {
    }
}
