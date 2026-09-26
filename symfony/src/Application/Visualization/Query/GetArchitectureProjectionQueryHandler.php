<?php

declare(strict_types=1);

namespace App\Application\Visualization\Query;

use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetArchitectureProjectionQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ArchitectureGraphQueryService $architecture)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetArchitectureProjectionQuery $query): array
    {
        return $this->architecture->projection($query->view, $query->focus, $query->depth);
    }
}
