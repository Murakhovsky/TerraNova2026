<?php

declare(strict_types=1);

namespace App\Application\Visualization\Query;

use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetArchitectureHealthQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ArchitectureGraphQueryService $architecture)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetArchitectureHealthQuery $query): array
    {
        return $this->architecture->health();
    }
}
