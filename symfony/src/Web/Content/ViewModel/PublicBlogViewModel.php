<?php

declare(strict_types=1);

namespace App\Web\Content\ViewModel;

final readonly class PublicBlogViewModel
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $pages,
        public int $total,
    ) {
    }

    public function state(): string
    {
        return $this->items === [] ? 'empty' : 'normal';
    }

    public function previousUrl(): ?string
    {
        return $this->page > 1 ? '/blog?page=' . ($this->page - 1) : null;
    }

    public function nextUrl(): ?string
    {
        return $this->page < $this->pages ? '/blog?page=' . ($this->page + 1) : null;
    }
}
