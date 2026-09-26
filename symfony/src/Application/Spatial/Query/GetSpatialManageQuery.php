<?php

declare(strict_types=1);

namespace App\Application\Spatial\Query;

use Kernel\Application\Query\QueryInterface;

final readonly class GetSpatialManageQuery implements QueryInterface
{
    /** @param array<string,mixed> $filters */
    public function __construct(public array $filters = [])
    {
    }
}
