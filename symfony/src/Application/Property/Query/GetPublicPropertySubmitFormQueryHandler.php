<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicPropertySubmitFormQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PropertyCatalogInterface $catalog)
    {
    }

    /** @return array{types:list<array<string,mixed>>} */
    public function __invoke(GetPublicPropertySubmitFormQuery $query): array
    {
        return [
            'types' => array_values(array_filter(
                $this->catalog->propertyTypes(),
                'is_array',
            )),
        ];
    }
}
