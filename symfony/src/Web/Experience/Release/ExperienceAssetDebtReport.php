<?php
declare(strict_types=1);

namespace App\Web\Experience\Release;

final readonly class ExperienceAssetDebtReport
{
    /**
     * @param list<string> $deadCss
     * @param list<string> $deadJs
     * @param list<string> $legacyArtifacts
     */
    public function __construct(
        public array $deadCss,
        public array $deadJs,
        public array $legacyArtifacts,
        public bool $viteBoundaryValid,
        public bool $canonicalRootsPresent,
    ) {}

    public function cssGreen(): bool
    {
        return $this->deadCss === [];
    }

    public function jsGreen(): bool
    {
        return $this->deadJs === [];
    }

    public function isGreen(): bool
    {
        return $this->cssGreen()
            && $this->jsGreen()
            && $this->legacyArtifacts === []
            && $this->viteBoundaryValid
            && $this->canonicalRootsPresent;
    }

    public function toArray(): array
    {
        return [
            'green' => $this->isGreen(),
            'dead_css' => $this->deadCss,
            'dead_js' => $this->deadJs,
            'legacy_artifacts' => $this->legacyArtifacts,
            'vite_boundary_valid' => $this->viteBoundaryValid,
            'canonical_roots_present' => $this->canonicalRootsPresent,
        ];
    }
}
