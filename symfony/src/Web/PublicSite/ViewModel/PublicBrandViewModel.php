<?php

declare(strict_types=1);

namespace App\Web\PublicSite\ViewModel;

final readonly class PublicBrandViewModel
{
    /**
     * @param list<array{title:string,text:string}> $sections
     * @param array<string,mixed> $formData
     */
    public function __construct(
        public string $slug,
        public string $eyebrow,
        public string $title,
        public string $description,
        public array $sections,
        public bool $hasForm,
        public array $formData = [],
        public ?string $notice = null,
        public string $noticeTone = 'info',
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }

    public function canonicalPath(): string
    {
        return '/' . $this->slug;
    }
}
