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

$publicPage = $read('app/Interfaces/Web/View/page/show.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/action_bar'",
    "partial('components/ui/state'",
    'name="request_intent"',
    'name="full_name"',
    'name="message"',
    'contacts',
] as $marker) {
    $contains($publicPage, $marker, 'Public Page canonical shell or contact contract is incomplete.');
}
foreach (['tn-page-hero', 'tn-breadcrumbs', 'tn-kicker', 'tn-hero-actions'] as $legacyMarker) {
    $notContains($publicPage, $legacyMarker, 'Public Page must not restore legacy shell primitives.');
}

$blogIndex = $read('app/Interfaces/Web/View/blog/index.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-blog-grid',
    'tn-blog-card',
    'tn-pagination',
    "blog?page=",
] as $marker) {
    $contains($blogIndex, $marker, 'Blog index canonical shell/pagination contract is incomplete.');
}
foreach (['tn-page-hero', 'tn-breadcrumbs', 'tn-kicker', 'tn-empty-state'] as $legacyMarker) {
    $notContains($blogIndex, $legacyMarker, 'Blog index must not restore legacy shell primitives.');
}

$blogShow = $read('app/Interfaces/Web/View/blog/show.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/action_bar'",
    'tn-article__body',
    'tn-article__cover',
    'tn-related-content',
    'application/ld+json',
    'JSON_UNESCAPED_UNICODE',
    'property/catalog',
    'contacts',
] as $marker) {
    $contains($blogShow, $marker, 'Blog article canonical/editorial/SEO contract is incomplete.');
}
foreach (['tn-breadcrumbs', 'tn-article__header', 'tn-kicker', 'tn-hero-actions', 'tn-section-heading'] as $legacyMarker) {
    $notContains($blogShow, $legacyMarker, 'Blog article must not restore legacy shell primitives.');
}

$seoLanding = $read('app/Interfaces/Web/View/blog/landing.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/action_bar'",
    'tn-seo-landing__media',
    'tn-article__body',
    'application/ld+json',
    'JSON_UNESCAPED_UNICODE',
    'property/catalog',
    'contacts',
] as $marker) {
    $contains($seoLanding, $marker, 'SEO landing canonical/content/schema contract is incomplete.');
}
foreach (['tn-page-hero', 'tn-breadcrumbs', 'tn-kicker', 'tn-page-hero__actions'] as $legacyMarker) {
    $notContains($seoLanding, $legacyMarker, 'SEO landing must not restore legacy shell primitives.');
}

$publicContentController = $read('symfony/src/Web/Content/PublicContentPageController.php');
foreach ([
    'public function blog(Request $request): Response',
    'public function article(Request $request, string $slug): Response',
    'public function guide(Request $request, string $slug): Response',
    "'blog/index'",
    "'blog/show'",
    "'blog/landing'",
    'metaTitle',
    'metaDescription',
    'metaUrl',
    'metaRobots',
] as $marker) {
    $contains($publicContentController, $marker, 'Public Content controller contract is incomplete.');
}

foreach ([
    'path: /blog',
    'PublicContentPageController::blog',
    'path: /blog/{slug}',
    'PublicContentPageController::article',
    'path: /guide/{slug}',
    'PublicContentPageController::guide',
] as $marker) {
    $contains($routes, $marker, 'Public Content route contract is incomplete.');
}

$docs = $read('docs/03-architecture/cos-remaining-shell-closure.md');
foreach ([
    '# Закриття залишкових UI shells',
    '## Хвиля 1',
    '### Architecture Explorer',
    '## Хвиля 2',
    '### Public Page',
    '### Blog',
    '### SEO landing',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 14 documentation is incomplete.');
}

echo "PHASE 14 remaining shell closure passed.\n";
