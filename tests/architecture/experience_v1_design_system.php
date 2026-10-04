<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'resources/experience/design-system/foundation.yaml',
    'symfony/src/Web/Experience/DesignSystem/DesignSystemAuditReport.php',
    'symfony/src/Web/Experience/DesignSystem/DesignSystemAuditService.php',
    'symfony/src/Command/DesignSystemAuditCommand.php',
];

foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('EX-002 artifact missing: ' . $file);
    }
}

$componentFiles = glob($root . '/symfony/src/Web/Experience/Component/*.php') ?: [];
$canonical = [];
$helpers = [];
foreach ($componentFiles as $file) {
    $name = pathinfo($file, PATHINFO_FILENAME);
    if (str_starts_with($name, 'Cos')) {
        $canonical[] = $name;
    } else {
        $helpers[] = $name;
    }
}
sort($canonical);
sort($helpers);

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');

foreach ($canonical as $component) {
    if (!str_contains($registry, "\$this->entry('" . $component . "'")) {
        throw new RuntimeException('Canonical component is missing from UI Catalog: ' . $component);
    }
}

$declaredEntries = substr_count($registry, "\$this->entry('");
if ($declaredEntries !== count($canonical)) {
    throw new RuntimeException(sprintf(
        'UI Catalog entry count does not match canonical component count: catalog=%d canonical=%d.',
        $declaredEntries,
        count($canonical),
    ));
}

if (count($componentFiles) !== 65 || count($canonical) !== 63 || count($helpers) !== 2) {
    throw new RuntimeException(sprintf(
        'EX-002 baseline changed unexpectedly: files=%d canonical=%d helpers=%d.',
        count($componentFiles),
        count($canonical),
        count($helpers),
    ));
}

foreach (['EXPERIMENTAL_COMPONENTS', 'DEPRECATED_COMPONENTS', "'stable'", "'experimental'", "'deprecated'"] as $marker) {
    if (!str_contains($registry, $marker)) {
        throw new RuntimeException('Design System maturity taxonomy missing marker: ' . $marker);
    }
}

$foundation = (string) file_get_contents($root . '/resources/experience/design-system/foundation.yaml');
foreach (['status: frozen', 'primary: Inter', 'supported: [light, dark, system]', 'supported: [comfortable, compact]', 'breaking_change_requires: ADR'] as $marker) {
    if (!str_contains($foundation, $marker)) {
        throw new RuntimeException('Design System V1 foundation freeze missing marker: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/DesignSystemCatalogController.php');
foreach (['DesignSystemAuditService', "'audit' =>"] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('/dev/ui does not expose Design System V1 audit: ' . $marker);
    }
}

echo "EX-002 Design System V1 audit/freeze contract OK\n";
