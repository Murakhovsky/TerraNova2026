<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PublicPropertySeoViewModel
{
    public function __construct(
        public PublicPropertyCatalogViewModel $catalog,
        public string $kicker,
        public string $title,
        public string $description,
        public string $canonicalUrl,
    ) {
    }

    public function state(): string
    {
        return $this->catalog->state();
    }
}
