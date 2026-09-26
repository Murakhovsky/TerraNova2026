<?php
declare(strict_types=1);

namespace App\Web\PublicSite;

use App\Web\PublicSite\ViewModel\PublicCosDomainViewModel;
use App\Web\PublicSite\ViewModel\PublicCosLandingViewModel;

final class PublicCosPresenter
{
    /** @param array<string,mixed> $data */
    public function landing(array $data): PublicCosLandingViewModel
    {
        return new PublicCosLandingViewModel(
            lang: (string)($data['lang'] ?? 'en'),
            languages: array_values(array_filter((array)($data['languages'] ?? []), 'is_string')),
            copy: is_array($data['copy'] ?? null) ? $data['copy'] : [],
            domains: is_array($data['domains'] ?? null) ? $data['domains'] : [],
            industries: is_array($data['industries'] ?? null) ? $data['industries'] : [],
            metaTitle: (string)($data['meta_title'] ?? 'COS — Company Operating System'),
            metaDescription: (string)($data['meta_description'] ?? ''),
        );
    }
    /** @param array<string,mixed> $data */
    public function domain(array $data): PublicCosDomainViewModel
    {
        return new PublicCosDomainViewModel(
            lang: (string)($data['lang'] ?? 'en'),
            slug: (string)($data['slug'] ?? ''),
            languages: array_values(array_filter((array)($data['languages'] ?? []), 'is_string')),
            copy: is_array($data['copy'] ?? null) ? $data['copy'] : [],
            domain: is_array($data['domain'] ?? null) ? $data['domain'] : [],
            detail: is_array($data['detail'] ?? null) ? $data['detail'] : [],
            presentation: is_array($data['presentation'] ?? null) ? $data['presentation'] : null,
            presentationLabel: (string)($data['presentation_label'] ?? 'COS'),
            metaTitle: (string)($data['meta_title'] ?? 'COS'),
            metaDescription: (string)($data['meta_description'] ?? ''),
        );
    }
}
