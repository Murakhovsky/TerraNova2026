<?php

declare(strict_types=1);

namespace App\Web\Content\ViewModel;

final readonly class ContentEditorViewModel
{
    /**
     * @param array<string,mixed> $item
     * @param list<array{title:string,subtitle:string,meta:string}> $revisions
     */
    public function __construct(
        public array $item,
        public array $revisions,
        public string $actionStatus = '',
        public bool $notFound = false,
    ) {
    }

    public function isNew(): bool
    {
        return (int) ($this->item['id'] ?? 0) <= 0;
    }

    public function publicHref(): ?string
    {
        $slug = trim((string) ($this->item['slug'] ?? ''));
        if (($this->item['status'] ?? '') !== 'published' || $slug === '') return null;

        return (($this->item['content_type'] ?? 'blog_post') === 'seo_landing' ? '/guide/' : '/blog/') . $slug;
    }
}
