<?php

declare(strict_types=1);

namespace App\Web\Experience\DesignSystem;

use App\Web\Experience\Dev\UiCatalogRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

final readonly class DesignSystemAuditService
{
    public function __construct(
        private UiCatalogRegistry $catalog,
        #[Autowire('%kernel.project_dir%/src/Web/Experience/Component')]
        private string $componentDirectory,
        #[Autowire('%kernel.project_dir%/../resources/experience/design-system/foundation.yaml')]
        private string $foundationContract,
    ) {
    }

    public function audit(): DesignSystemAuditReport
    {
        $files = glob($this->componentDirectory . '/*.php') ?: [];
        $classes = array_values(array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            $files,
        ));
        sort($classes);

        $canonical = array_values(array_filter(
            $classes,
            static fn (string $name): bool => str_starts_with($name, 'Cos'),
        ));
        $helpers = array_values(array_diff($classes, $canonical));

        $entries = $this->catalog->entries();
        $catalogNames = array_map(static fn ($entry): string => $entry->name, $entries);
        sort($catalogNames);

        $stats = $this->catalog->stats();
        $foundation = is_file($this->foundationContract)
            ? Yaml::parseFile($this->foundationContract)
            : [];
        $foundation = is_array($foundation) ? $foundation : [];

        return new DesignSystemAuditReport(
            componentClassFiles: count($classes),
            canonicalComponents: count($canonical),
            catalogEntries: count($entries),
            stable: $stats['stable'],
            experimental: $stats['experimental'],
            deprecated: $stats['deprecated'],
            missingCatalogEntries: array_values(array_diff($canonical, $catalogNames)),
            orphanCatalogEntries: array_values(array_diff($catalogNames, $canonical)),
            runtimeHelpers: $helpers,
            tokensFrozen: ($foundation['tokens']['status'] ?? null) === 'frozen',
            typographyFrozen: ($foundation['typography']['status'] ?? null) === 'frozen',
            themeParityDeclared: ($foundation['themes']['parity'] ?? null) === 'required',
        );
    }
}
