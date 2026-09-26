<?php

declare(strict_types=1);

namespace App\Web\PublicSite;

use App\Web\PublicSite\ViewModel\PublicBrandViewModel;

final class PublicBrandPresenter
{
    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed> $formData
     * @param array{message:string,tone:string}|null $notice
     */
    public function present(
        array $page,
        array $formData = [],
        ?array $notice = null,
        ?string $error = null,
    ): PublicBrandViewModel {
        $sections = [];
        foreach (($page['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }

            $title = trim((string) ($section['title'] ?? ''));
            $text = trim((string) ($section['text'] ?? ''));
            if ($title === '' && $text === '') {
                continue;
            }

            $sections[] = ['title' => $title, 'text' => $text];
        }

        return new PublicBrandViewModel(
            slug: trim((string) ($page['path'] ?? '')),
            eyebrow: trim((string) ($page['kicker'] ?? 'Terra Nova')),
            title: trim((string) ($page['title'] ?? 'Terra Nova CLUB')),
            description: trim((string) ($page['description'] ?? '')),
            sections: $sections,
            hasForm: (bool) ($page['has_form'] ?? false),
            formData: $formData,
            notice: $notice['message'] ?? null,
            noticeTone: $notice['tone'] ?? 'info',
            error: $error,
        );
    }
}
