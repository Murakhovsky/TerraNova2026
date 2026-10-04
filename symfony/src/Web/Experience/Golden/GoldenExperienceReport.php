<?php

declare(strict_types=1);

namespace App\Web\Experience\Golden;

final readonly class GoldenExperienceReport
{
    /**
     * @param list<array<string,mixed>> $pages
     * @param list<string> $missing
     */
    public function __construct(
        public int $required,
        public int $registered,
        public int $implemented,
        public int $ready,
        public array $pages,
        public array $missing,
        public bool $massMigrationBlocked,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missing === []
            && $this->registered === $this->required
            && $this->ready === $this->required;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'registered' => $this->registered,
            'implemented' => $this->implemented,
            'ready' => $this->ready,
            'pages' => $this->pages,
            'missing' => $this->missing,
            'massMigrationBlocked' => $this->massMigrationBlocked,
            'complete' => $this->isComplete(),
        ];
    }
}
