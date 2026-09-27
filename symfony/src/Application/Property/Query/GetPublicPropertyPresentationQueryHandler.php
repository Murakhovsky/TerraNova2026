<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use App\Application\Property\Service\PublicPropertyReadService;
use Domains\Property\Application\Contract\PropertyCatalogInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicPropertyPresentationQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private PublicPropertyReadService $publicProperties,
        private PropertyCatalogInterface $catalog,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function __invoke(GetPublicPropertyPresentationQuery $query): ?array
    {
        $property = $this->publicProperties->show($query->slug);
        if (is_array($property)) {
            return ['kind' => 'property', 'data' => $property];
        }

        $group = $this->catalog->propertyGroupBySlug($query->slug);
        if (!is_array($group)) {
            return null;
        }

        return [
            'kind' => 'group',
            'group' => $group,
            'properties' => $this->catalog->propertyGroupPresentationProperties((int) ($group['id'] ?? 0)),
        ];
    }
}
