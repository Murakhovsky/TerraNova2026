<?php

declare(strict_types=1);

namespace App\Web\Content\ViewModel;

final readonly class PublicArticleViewModel
{
    /**
     * @param array<string,mixed> $article
     * @param list<array<string,mixed>> $related
     * @param array<string,mixed> $schema
     */
    public function __construct(
        public array $article,
        public array $related,
        public string $canonicalUrl,
        public string $metaTitle,
        public string $metaDescription,
        public string $metaImage,
        public string $robots,
        public array $schema,
    ) {
    }
}
