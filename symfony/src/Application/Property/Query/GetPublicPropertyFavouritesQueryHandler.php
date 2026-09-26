<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use App\Application\Property\Service\PublicPropertyReadService;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicPropertyFavouritesQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PublicPropertyReadService $properties)
    {
    }

    /** @return array{properties:list<array<string,mixed>>} */
    public function __invoke(GetPublicPropertyFavouritesQuery $query): array
    {
        return $this->properties->favourites($query->publicIds);
    }
}
