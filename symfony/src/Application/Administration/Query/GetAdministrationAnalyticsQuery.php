<?php

declare(strict_types=1);

namespace App\Application\Administration\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetAdministrationAnalyticsQuery implements QueryInterface
{
    public function __construct(public int $days = 30)
    {
    }
}
