<?php
declare(strict_types=1);

namespace App\Web\PublicSite\ViewModel;

final readonly class PublicCosLandingViewModel
{
    /** @param list<string> $languages
     *  @param array<string,mixed> $copy
     *  @param array<string,array<string,mixed>> $domains
     *  @param array<string,string> $industries
     */
    public function __construct(
        public string $lang,
        public array $languages,
        public array $copy,
        public array $domains,
        public array $industries,
        public string $metaTitle,
        public string $metaDescription,
    ) {}

    public function canonicalPath(): string
    {
        return '/cos/' . $this->lang;
    }
}
