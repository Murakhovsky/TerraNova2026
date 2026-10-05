<?php

declare(strict_types=1);

namespace App\Web\Experience\Golden;

final readonly class GoldenStructureAuditReport
{
    /**
     * @param list<array{id:string,template:string,passed:bool,missing:list<string>,forbidden:list<string>}> $pages
     */
    public function __construct(public array $pages)
    {
    }

    public function passed(): int
    {
        return count(array_filter($this->pages, static fn (array $page): bool => $page['passed']));
    }

    public function isGreen(): bool
    {
        return $this->pages !== [] && $this->passed() === count($this->pages);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'green' => $this->isGreen(),
            'passed' => $this->passed(),
            'total' => count($this->pages),
            'pages' => $this->pages,
        ];
    }
}
