<?php
declare(strict_types=1);

namespace App\Web\PublicSite\ViewModel;

final readonly class PublicCosDomainViewModel
{
    /** @param list<string> $languages
     *  @param array<string,mixed> $copy
     *  @param array<string,mixed> $domain
     *  @param array<string,mixed> $detail
     *  @param array<string,mixed>|null $presentation
     */
    public function __construct(
        public string $lang,
        public string $slug,
        public array $languages,
        public array $copy,
        public array $domain,
        public array $detail,
        public ?array $presentation,
        public string $presentationLabel,
        public string $metaTitle,
        public string $metaDescription,
    ) {}

    public function canonicalPath(): string
    {
        return '/cos/' . $this->lang . '/domains/' . $this->slug;
    }
}
