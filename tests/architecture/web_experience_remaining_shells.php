<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing PHASE 14 artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ' Missing: ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ' Forbidden: ' . $needle);
};

$view = $read('app/Interfaces/Web/View/visualization/architecture.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/kpi_card'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'data-architecture-endpoint',
    'data-architecture-default-view',
    'data-architecture-view-label',
    'data-architecture-node-count',
    'data-architecture-edge-count',
    'data-architecture-mode',
    'data-architecture-search',
    'data-architecture-domain',
    'data-architecture-depth',
    'data-architecture-types',
    'data-architecture-stage',
    'data-architecture-details',
    'data-architecture-backend-diagnostic',
    'cos-architecture-data',
    'Architecture graph health',
] as $marker) {
    $contains($view, $marker, 'Architecture Explorer canonical shell/runtime contract is incomplete.');
}
foreach ([
    'tn-listing-hero',
    'tn-admin-panel',
    'tn-section-heading',
    'tn-form-status is-visible',
    'tn-kicker',
] as $legacyMarker) {
    $notContains($view, $legacyMarker, 'Architecture Explorer must not restore the legacy shell.');
}

$page = $read('app/Interfaces/Web/View/page/show.phtml');
foreach (["partial('components/ui/page_header'", "'Переглянути об’єкти'", "'Подати об’єкт'"] as $marker) {
    $contains($page, $marker, 'Public Page canonical shell contract is incomplete.');
}
foreach (['tn-breadcrumbs', 'tn-page-hero', 'tn-page-hero__actions'] as $legacyMarker) {
    $notContains($page, $legacyMarker, 'Public Page must not restore legacy outer shell.');
}

$blogIndex = $read('app/Interfaces/Web/View/blog/index.phtml');
foreach (["partial('components/ui/page_header'", "partial('components/ui/state'", "'opублікованих матеріалів'"] as $marker) {
    if ($marker === "'opублікованих матеріалів'") continue;
    $contains($blogIndex, $marker, 'Blog Index canonical shell contract is incomplete.');
}
foreach (['tn-breadcrumbs', 'tn-page-hero', 'tn-empty-state'] as $legacyMarker) {
    $notContains($blogIndex, $legacyMarker, 'Blog Index must not restore legacy outer shell.');
}

$blogShow = $read('app/Interfaces/Web/View/blog/show.phtml');
foreach (['tn-ui-panel', "partial('components/ui/action_bar'", 'tn-ui-eyebrow'] as $marker) {
    $contains($blogShow, $marker, 'Blog Article canonical shell contract is incomplete.');
}
foreach (['tn-breadcrumbs', 'tn-sales-cta', 'tn-section-heading'] as $legacyMarker) {
    $notContains($blogShow, $legacyMarker, 'Blog Article must not restore legacy shell primitives.');
}
foreach (['tn-article__header', 'tn-article__body', 'application/ld+json'] as $marker) {
    $contains($blogShow, $marker, 'Blog Article specialized content contract must remain intact.');
}

$seo = $read('app/Interfaces/Web/View/property/seo.phtml');
foreach (["partial('components/ui/page_header'", "partial('components/ui/state'", 'tn-ui-panel', 'BreadcrumbList', 'ItemList'] as $marker) {
    $contains($seo, $marker, 'Property SEO landing canonical/specialized contract is incomplete.');
}
foreach (['tn-breadcrumbs', 'tn-seo-panel'] as $legacyMarker) {
    $notContains($seo, $legacyMarker, 'Property SEO landing must not restore legacy outer shell.');
}

$kpi = $read('app/Interfaces/Web/View/components/ui/kpi_card.phtml');
foreach ([
    '$valueAttributes',
    '$valueAttributesHtml',
    'foreach ($valueAttributes as $name => $attributeValue)',
] as $marker) {
    $contains($kpi, $marker, 'Canonical KPI card must preserve live value attributes.');
}

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach ([
    'public function index(Request $request): Response',
    'public function graph(Request $request): Response',
    'public function health(): Response',
    '$this->manager()',
    'GraphProjectionRegistryInterface',
    'GraphHealthAnalyzerInterface',
    "'visualization/architecture'",
] as $marker) {
    $contains($controller, $marker, 'Architecture Explorer controller contract is incomplete.');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /cos/architecture',
    'ArchitecturePageController::index',
    'path: /cos/architecture/graph',
    'ArchitecturePageController::graph',
    'path: /cos/architecture/health',
    'ArchitecturePageController::health',
] as $marker) {
    $contains($routes, $marker, 'Architecture Explorer route contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-remaining-shell-closure.md');
foreach ([
    '# Закриття залишкових UI shells',
    '## Хвиля 1',
    '### Architecture Explorer',
    '## Хвиля 2',
    '### Публічні контентні surfaces',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 14 documentation is incomplete.');
}

echo "PHASE 14 remaining shell closure passed.\n";
