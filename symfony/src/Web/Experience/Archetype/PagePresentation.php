<?php

declare(strict_types=1);

namespace App\Web\Experience\Archetype;

final readonly class PagePresentation
{
    /**
     * @param list<string> $patterns
     */
    public function __construct(
        public PageArchetypeDefinition $archetype,
        public array $patterns,
        public string $state,
        public string $density,
    ) {
    }

    public function archetypeId(): string
    {
        return $this->archetype->id->value;
    }
}
