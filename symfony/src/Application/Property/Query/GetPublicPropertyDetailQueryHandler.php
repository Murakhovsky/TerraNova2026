<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use App\Application\Property\Service\PublicPropertyReadService;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicPropertyDetailQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PublicPropertyReadService $properties)
    {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(GetPublicPropertyDetailQuery $query): ?array
    {
        return $this->properties->show($query->slug);
    }
}
