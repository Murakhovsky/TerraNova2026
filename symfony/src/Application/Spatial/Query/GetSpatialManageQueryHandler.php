<?php

declare(strict_types=1);

namespace App\Application\Spatial\Query;

use Domains\Spatial\Application\Contract\SpatialSceneInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetSpatialManageQueryHandler implements QueryHandlerInterface
{
    public function __construct(private SpatialSceneInterface $scenes)
    {
    }

    /** @return array{scenes:list<array<string,mixed>>,stats:array<string,mixed>,filters:array<string,mixed>} */
    public function __invoke(GetSpatialManageQuery $query): array
    {
        return [
            'scenes' => $this->scenes->managerScenes($query->filters),
            'stats' => $this->scenes->stats(),
            'filters' => $query->filters,
        ];
    }
}
