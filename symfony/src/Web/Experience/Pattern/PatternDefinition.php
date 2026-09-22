<?php

declare(strict_types=1);

namespace App\Web\Experience\Pattern;

use App\Web\Experience\Visual\VisualStability;

final readonly class PatternDefinition
{
    /**
     * @param list<string> $props
     * @param list<string> $slots
     * @param list<string> $variants
     * @param list<string> $sizes
     * @param list<string> $states
     * @param list<string> $responsiveBehavior
     * @param list<string> $accessibilityRules
     * @param list<string> $dependencies
     */
    public function __construct(
        public string $name,
        public string $purpose,
        public array $props,
        public array $slots,
        public array $variants,
        public array $sizes,
        public array $states,
        public array $responsiveBehavior,
        public array $accessibilityRules,
        public array $dependencies,
        public string $owner,
        public VisualStability $stability,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'purpose' => $this->purpose,
            'props' => $this->props,
            'slots' => $this->slots,
            'variants' => $this->variants,
            'sizes' => $this->sizes,
            'states' => $this->states,
            'responsiveBehavior' => $this->responsiveBehavior,
            'accessibilityRules' => $this->accessibilityRules,
            'dependencies' => $this->dependencies,
            'owner' => $this->owner,
            'stability' => $this->stability->value,
        ];
    }
}
