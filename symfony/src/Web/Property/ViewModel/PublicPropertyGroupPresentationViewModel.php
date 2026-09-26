<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PublicPropertyGroupPresentationViewModel
{
    /** @param list<array<string,mixed>> $properties */
    public function __construct(
        public string $slug,
        public string $title,
        public string $description,
        public string $location,
        public array $properties,
        public string $canonicalUrl,
    ) {
    }
}
