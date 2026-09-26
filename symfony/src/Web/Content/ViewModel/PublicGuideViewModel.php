<?php

declare(strict_types=1);

namespace App\Web\Content\ViewModel;

final readonly class PublicGuideViewModel
{
    /** @param array<string,mixed> $landing @param array<string,mixed> $schema */
    public function __construct(
        public array $landing,
        public string $canonicalUrl,
        public string $metaTitle,
        public string $metaDescription,
        public string $metaImage,
        public string $robots,
        public array $schema,
    ) {
    }
}
