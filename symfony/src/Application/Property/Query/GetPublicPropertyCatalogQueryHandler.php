<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use App\Application\Property\Service\PublicPropertyReadService;
use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicPropertyCatalogQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private PublicPropertyReadService $properties,
        private PropertyCatalogInterface $catalog,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetPublicPropertyCatalogQuery $query): array
    {
        return $this->properties->catalog($query->query) + [
            'types' => $this->catalog->propertyTypes(),
            'locations' => $this->catalog->locations(),
        ];
    }
}
