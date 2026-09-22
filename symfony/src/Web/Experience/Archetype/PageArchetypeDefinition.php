<?php

declare(strict_types=1);

namespace App\Web\Experience\Archetype;

use App\Web\Experience\Visual\VisualStability;

final readonly class PageArchetypeDefinition
{
    /**
     * @param list<string> $requiredPatterns
     * @param list<string> $optionalPatterns
     * @param list<string> $states
     * @param list<string> $densities
     * @param list<string> $responsiveContract
     */
    public function __construct(
        public PageArchetype $id,
        public string $surface,
        public string $purpose,
        public array $requiredPatterns,
        public array $optionalPatterns,
        public array $states,
        public array $densities,
        public array $responsiveContract,
        public VisualStability $stability,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'surface' => $this->surface,
            'purpose' => $this->purpose,
            'requiredPatterns' => $this->requiredPatterns,
            'optionalPatterns' => $this->optionalPatterns,
            'states' => $this->states,
            'densities' => $this->densities,
            'responsiveContract' => $this->responsiveContract,
            'stability' => $this->stability->value,
        ];
    }
}
