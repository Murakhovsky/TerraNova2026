<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Component/CosPageHeader.php',
    'symfony/templates/components/experience/cos_page_header.html.twig',
    'symfony/src/Web/Experience/Archetype/PagePresentation.php',
    'symfony/src/Web/Experience/Archetype/PagePresentationFactory.php',
    'symfony/src/Application/Experience/Query/GetExecutiveDashboardQuery.php',
    'symfony/src/Application/Experience/Query/GetExecutiveDashboardQueryHandler.php',
    'symfony/src/Web/Workspace/ViewModel/ExecutiveDashboardViewModel.php',
    'symfony/src/Web/Workspace/ExecutiveDashboardPresenter.php',
    'symfony/src/Web/Experience/Shell/WorkspaceShellFactory.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-001 reusable foundation artifact is missing: ' . $relative);
    }
}

$patternsCss = (string) file_get_contents($root . '/symfony/assets/styles/business-patterns.css');
foreach (['.cos-page-header', '.cos-section-title', '.cos-kpi-strip', '.cos-pattern-stack'] as $selector) {
    if (!str_contains($patternsCss, $selector)) {
        throw new RuntimeException('VR-001 reusable pattern style is missing: ' . $selector);
    }
}

$pageHeader = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_page_header.html.twig');
foreach (['cos-page-header', 'cos-page-header__title', "block('content')"] as $marker) {
    if (!str_contains($pageHeader, $marker)) {
        throw new RuntimeException('CosPageHeader contract is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($pageHeader, $forbidden)) {
        throw new RuntimeException('CosPageHeader contains legacy/local visual behavior: ' . $forbidden);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/UiCatalogRegistry.php');
if (!str_contains($catalog, "entry('CosPageHeader'")) {
    throw new RuntimeException('CosPageHeader must be governed by /dev/ui catalog.');
}

$presentation = (string) file_get_contents($root . '/symfony/src/Web/Experience/Archetype/PagePresentationFactory.php');
foreach (['requiredPatterns', 'requiredPatternGroups', 'Unsupported page state', 'Unsupported density', 'PatternRegistry'] as $marker) {
    if (!str_contains($presentation, $marker)) {
        throw new RuntimeException('PagePresentationFactory does not enforce archetype contract: ' . $marker);
    }
}

$queryHandler = (string) file_get_contents($root . '/symfony/src/Application/Experience/Query/GetExecutiveDashboardQueryHandler.php');
foreach ([
    'SalesWorkspaceReadModelInterface',
    'PropertyWorkspaceReadModelInterface',
    'OperationsReadModelInterface',
    'ActiveModuleResolver',
] as $marker) {
    if (!str_contains($queryHandler, $marker)) {
        throw new RuntimeException('Executive dashboard query lost a source contract: ' . $marker);
    }
}
foreach (['App\\Web\\', 'PhtmlRenderer', 'Twig'] as $forbidden) {
    if (str_contains($queryHandler, $forbidden)) {
        throw new RuntimeException('Application query leaked Web/presentation dependency: ' . $forbidden);
    }
}

$presenter = (string) file_get_contents($root . '/symfony/src/Web/Workspace/ExecutiveDashboardPresenter.php');
foreach (['Domains\\', 'Doctrine\\', 'Repository', 'PhtmlRenderer'] as $forbidden) {
    if (str_contains($presenter, $forbidden)) {
        throw new RuntimeException('ExecutiveDashboardPresenter leaked business/persistence dependency: ' . $forbidden);
    }
}

$shellFactory = (string) file_get_contents($root . '/symfony/src/Web/Experience/Shell/WorkspaceShellFactory.php');
foreach (['ProviderBackedShellNavigation', 'ShellViewModel', 'WebExtensionContext'] as $marker) {
    if (!str_contains($shellFactory, $marker)) {
        throw new RuntimeException('WorkspaceShellFactory contract is incomplete: ' . $marker);
    }
}

echo "Wave 13 VR-001 reusable foundation passed.\n";
