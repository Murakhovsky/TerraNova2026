<?php
declare(strict_types=1);

namespace App\Web\Experience\External;

final readonly class ExternalExperienceReport
{
    /**
     * @param array<string,int> $surfaces
     * @param array<string,int> $domains
     * @param list<array<string,mixed>> $pages
     */
    public function __construct(
        public int $pages,
        public array $surfaces,
        public array $domains,
        public array $pagesList,
        public int $p0,
        public int $p1,
        public int $v1Ready,
    ) {}

    public function toArray(): array
    {
        return [
            'pages' => $this->pages,
            'surfaces' => $this->surfaces,
            'domains' => $this->domains,
            'p0' => $this->p0,
            'p1' => $this->p1,
            'v1_ready' => $this->v1Ready,
            'pages_list' => $this->pagesList,
        ];
    }
}
