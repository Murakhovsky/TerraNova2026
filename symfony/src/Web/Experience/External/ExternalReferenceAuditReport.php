<?php
declare(strict_types=1);

namespace App\Web\Experience\External;

final readonly class ExternalReferenceAuditReport
{
    /** @param list<array<string,mixed>> $pages */
    public function __construct(public array $pages) {}

    public function passed(): int
    {
        return count(array_filter($this->pages, static fn (array $page): bool => $page['passed']));
    }

    public function isGreen(): bool
    {
        return count($this->pages) === 4 && $this->passed() === 4;
    }

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
