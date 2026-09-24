<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use App\Application\Property\Service\PublicPropertyReadService;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPropertyMapQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PublicPropertyReadService $properties)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetPropertyMapQuery $query): array
    {
        return $this->properties->catalog(array_replace(
            $query->filters,
            ['page' => 1, 'per_page' => 60],
        ));
    }
}
