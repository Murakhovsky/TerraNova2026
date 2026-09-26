<?php

declare(strict_types=1);

namespace App\Application\Visualization\Query;

use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetArchitectureOverviewQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ArchitectureGraphQueryService $architecture)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetArchitectureOverviewQuery $query): array
    {
        return $this->architecture->overview();
    }
}
