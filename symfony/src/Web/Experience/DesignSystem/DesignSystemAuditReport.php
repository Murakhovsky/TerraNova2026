<?php

declare(strict_types=1);

namespace App\Web\Experience\DesignSystem;

final readonly class DesignSystemAuditReport
{
    /**
     * @param list<string> $missingCatalogEntries
     * @param list<string> $orphanCatalogEntries
     * @param list<string> $runtimeHelpers
     */
    public function __construct(
        public int $componentClassFiles,
        public int $canonicalComponents,
        public int $catalogEntries,
        public int $stable,
        public int $experimental,
        public int $deprecated,
        public array $missingCatalogEntries,
        public array $orphanCatalogEntries,
        public array $runtimeHelpers,
        public bool $tokensFrozen,
        public bool $typographyFrozen,
        public bool $themeParityDeclared,
        public bool $patternsFrozen,
        public bool $archetypesFrozen,
    ) {
    }

    public function isGreen(): bool
    {
        return $this->canonicalComponents === $this->catalogEntries
            && $this->missingCatalogEntries === []
            && $this->orphanCatalogEntries === []
            && $this->tokensFrozen
            && $this->typographyFrozen
            && $this->themeParityDeclared
            && $this->patternsFrozen
            && $this->archetypesFrozen;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'green' => $this->isGreen(),
            'componentClassFiles' => $this->componentClassFiles,
            'canonicalComponents' => $this->canonicalComponents,
            'catalogEntries' => $this->catalogEntries,
            'stable' => $this->stable,
            'experimental' => $this->experimental,
            'deprecated' => $this->deprecated,
            'runtimeHelpers' => $this->runtimeHelpers,
            'missingCatalogEntries' => $this->missingCatalogEntries,
            'orphanCatalogEntries' => $this->orphanCatalogEntries,
            'tokensFrozen' => $this->tokensFrozen,
            'typographyFrozen' => $this->typographyFrozen,
            'themeParityDeclared' => $this->themeParityDeclared,
            'patternsFrozen' => $this->patternsFrozen,
            'archetypesFrozen' => $this->archetypesFrozen,
        ];
    }
}
