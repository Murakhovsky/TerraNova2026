<?php

declare(strict_types=1);

namespace App\Web\Property;

use App\Web\Property\ViewModel\PublicPropertyCatalogViewModel;
use App\Web\Property\ViewModel\PublicPropertySeoViewModel;

final class PublicPropertySeoPresenter
{
    public function present(
        PublicPropertyCatalogViewModel $catalog,
        string $kicker,
        string $baseTitle,
        string $baseDescription,
        string $canonicalUrl,
    ): PublicPropertySeoViewModel {
        $title = $baseTitle;
        $description = $baseDescription;

        $typeCode = (string) ($catalog->filters['type'] ?? '');
        foreach ($catalog->types as $type) {
            if ($typeCode !== (string) ($type['code'] ?? '')) {
                continue;
            }

            $typeName = trim((string) ($type['name_uk'] ?? $typeCode));
            if ($typeName !== '') {
                $title .= ': ' . $typeName;
                $description = 'Актуальні обʼєкти типу ' . $typeName
                    . ' у каталозі Terra Nova CLUB: ціни, площі, медіа та повні картки.';
            }
            break;
        }

        $locationSlug = (string) ($catalog->filters['location'] ?? '');
        foreach ($catalog->locations as $location) {
            if ($locationSlug !== (string) ($location['slug'] ?? '')) {
                continue;
            }

            $city = trim((string) ($location['city'] ?? $locationSlug));
            if ($city !== '') {
                $title .= ': ' . $city;
                $description = 'Добірка нерухомості у ' . $city
                    . ' з актуальними цінами, характеристиками та швидким переходом у картку обʼєкта.';
            }
            break;
        }

        return new PublicPropertySeoViewModel(
            catalog: $catalog,
            kicker: $kicker,
            title: $title,
            description: $description,
            canonicalUrl: $canonicalUrl,
        );
    }
}
