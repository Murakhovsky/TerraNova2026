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

$view = $read('symfony/templates/experience/system/architecture.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    'data-controller="architecture-explorer"',
    'data-architecture-explorer-endpoint-value="/cos/architecture/graph"',
    'data-architecture-explorer-target="stage"',
    'data-architecture-explorer-target="details"',
    'cos-architecture__shell',
    'Graph Health',
] as $marker) {
    $contains($view, $marker, 'Architecture Explorer canonical System Control Surface contract is incomplete.');
}
foreach (['tn-', 'style=', '<script', '<table', 'cos-architecture-data'] as $legacyMarker) {
    $notContains($view, $legacyMarker, 'Architecture Explorer must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/visualization/architecture.phtml')) {
    throw new RuntimeException('Retired Architecture Explorer PHTML restored.');
}

$brandPage = $read('symfony/templates/experience/public/brand_page.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosCard', 'data-cos-public-brand=', '<twig:CosActionBar'] as $marker) {
    $contains($brandPage, $marker, 'Public Brand canonical shell contract is incomplete.');
}
foreach (['tn-', 'style=', 'onclick=', '<script'] as $legacyMarker) {
    $notContains($brandPage, $legacyMarker, 'Public Brand must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/page/show.phtml')) {
    throw new RuntimeException('Retired Public Brand PHTML restored.');
}

$blogIndex = $read('app/Interfaces/Web/View/blog/index.phtml');
foreach (["partial('components/ui/page_header'", "partial('components/ui/state'", "'опублікованих матеріалів'"] as $marker) {
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

$seo = $read('symfony/templates/experience/public/property_seo.html.twig');
foreach (['<twig:CosPageHeader', 'data-cos-public="property-seo"', 'application/ld+json'] as $marker) {
    $contains($seo, $marker, 'Property SEO collection canonical Public Catalog contract is incomplete.');
}
foreach (['tn-', 'style=', 'onclick='] as $legacyMarker) {
    $notContains($seo, $legacyMarker, 'Property SEO collection must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/property/seo.phtml')) {
    throw new RuntimeException('Retired Property SEO PHTML restored.');
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
    'public function index(): Response',
    'public function graph(Request $request): Response',
    'public function health(): Response',
    '$this->manager()',
    'QueryBusInterface',
    'GetArchitectureOverviewQuery',
    'GetArchitectureProjectionQuery',
    'GetArchitectureHealthQuery',
    "experience/system/architecture.html.twig",
] as $marker) {
    $contains($controller, $marker, 'Architecture Explorer controller contract is incomplete.');
}
foreach (['GraphProjectionRegistryInterface', 'GraphHealthAnalyzerInterface', 'PhtmlRenderer', 'NavigationBuilder'] as $retired) {
    $notContains($controller, $retired, 'Architecture Explorer controller must remain on the Application Query boundary.');
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

$login = $read('app/Interfaces/Web/View/auth/login.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'tn-ui-button tn-ui-button--primary',
    'action="<?php echo $this->url->get(\'auth/login\'); ?>"',
    'name="email"',
    'name="password"',
    'autocomplete="email"',
    'autocomplete="current-password"',
] as $marker) {
    $contains($login, $marker, 'Login canonical/auth contract is incomplete.');
}
foreach (['tn-auth-copy', 'tn-kicker', 'tn-form-status is-visible', 'tn-btn tn-btn--accent'] as $legacyMarker) {
    $notContains($login, $legacyMarker, 'Login must not restore legacy shell primitives.');
}

$register = $read('app/Interfaces/Web/View/auth/register.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/state'",
    'tn-ui-panel',
    'tn-ui-button tn-ui-button--primary',
    'action="<?php echo $this->url->get(\'auth/register\'); ?>"',
    'name="full_name"',
    'name="email"',
    'name="phone"',
    'name="role"',
    'name="password"',
    'name="password_repeat"',
    'minlength="8"',
] as $marker) {
    $contains($register, $marker, 'Registration canonical/auth contract is incomplete.');
}
foreach (['tn-auth-copy', 'tn-kicker', 'tn-form-status is-visible', 'tn-btn tn-btn--accent'] as $legacyMarker) {
    $notContains($register, $legacyMarker, 'Registration must not restore legacy shell primitives.');
}

$cabinet = $read('symfony/templates/experience/portal/cabinet.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosCard',
    'data-cos-portal="cabinet"',
    '/auth/logout',
] as $marker) {
    $contains($cabinet, $marker, 'Wave 13 Cabinet Portal contract is incomplete.');
}
$retiredSubmission = $read('symfony/templates/experience/portal/submission_retired.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosAlert',
    'data-cos-portal="retired-submission"',
    '/cabinet',
] as $marker) {
    $contains($retiredSubmission, $marker, 'Wave 13 retired submission Portal contract is incomplete.');
}
foreach ([$cabinet, $retiredSubmission] as $surface) {
    foreach (['tn-', 'style=', '<script'] as $legacyMarker) {
        $notContains($surface, $legacyMarker, 'Wave 13 Portal must not restore legacy/local presentation.');
    }
}

$authController = $read('symfony/src/Web/Auth/AuthPageController.php');
foreach ([
    'public function login(Request $request): Response',
    'public function register(Request $request): Response',
    'public function logout(Request $request): Response',
    '$this->accounts->authenticate($form)',
    '$this->accounts->register($form)',
    '$session->migrate(true)',
    '$session->set(\'tn_auth_user_id\'',
    '$session->set(\'cos_organization_id\'',
    '$session->set(\'cos_csrf_token\'',
    '$request->getSession()->invalidate()',
    "'auth/login'",
    "'auth/register'",
] as $marker) {
    $contains($authController, $marker, 'Native Auth controller/session contract is incomplete.');
}

$cabinetController = $read('symfony/src/Web/Portal/CabinetController.php');
foreach ([
    'public function index(): Response',
    'public function retiredSubmission(string $id): Response',
    '$this->tenants->current()',
    "new RedirectResponse('/auth/login')",
    "new RedirectResponse('/sales')",
    'PageArchetype::Portal',
    'Response::HTTP_GONE',
] as $marker) {
    $contains($cabinetController, $marker, 'Wave 13 Cabinet controller contract is incomplete.');
}

foreach ([
    'path: /auth/login',
    'AuthPageController::login',
    'path: /auth/register',
    'AuthPageController::register',
    'path: /auth/logout',
    'AuthPageController::logout',
    'path: /cabinet',
    'App\\Web\\Portal\\CabinetController::index',
    'path: /cabinet/submission/{id}',
    'App\\Web\\Portal\\CabinetController::retiredSubmission',
] as $marker) {
    $contains($routes, $marker, 'Auth/Cabinet route contract is incomplete.');
}

$guide = $read('app/Interfaces/Web/View/blog/landing.phtml');
foreach ([
    "partial('components/ui/page_header'",
    "partial('components/ui/action_bar'",
    'tn-ui-panel',
    'tn-article__body',
    'application/ld+json',
] as $marker) {
    $contains($guide, $marker, 'Guide landing canonical/content contract is incomplete.');
}
foreach ([
    'tn-breadcrumbs',
    'tn-page-hero',
    'tn-page-hero__actions',
    'class="tn-sales-cta"',
] as $legacyMarker) {
    $notContains($guide, $legacyMarker, 'Guide landing must not restore legacy outer shell.');
}

foreach ([
    'app/Interfaces/Web/View/cabinet/index.phtml',
    'app/Interfaces/Web/View/cabinet/submission.phtml',
    'app/Interfaces/Web/View/index/public.phtml',
] as $historicalView) {
    if (is_file($root . '/' . $historicalView)) {
        throw new RuntimeException('Historical unrouted shell artifact restored: ' . $historicalView);
    }
}

$methodology = $read('symfony/templates/experience/system/methodology_studio.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosToolbar', 'data-controller="diagnostic-methodology"', 'data-editor'] as $marker) {
    $contains($methodology, $marker, 'Methodology Studio canonical specialized-island contract is incomplete.');
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    $notContains($methodology, $forbidden, 'Methodology Studio must not restore legacy outer-shell presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/methodology_studio/index.phtml')) {
    throw new RuntimeException('Retired Methodology Studio PHTML restored.');
}

$publicHome = $read('symfony/templates/experience/public/home.html.twig');
foreach (['<twig:CosPageHeader', '<twig:CosCard', 'Company Operating System', 'data-cos-public="home"'] as $marker) {
    $contains($publicHome, $marker, 'Wave 13 Public home contract is incomplete.');
}
foreach (['tn-', 'style=', '<script'] as $legacyMarker) {
    $notContains($publicHome, $legacyMarker, 'Wave 13 Public home must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/home/canonical.phtml')) {
    throw new RuntimeException('Retired home PHTML restored.');
}

$specializedContracts = [
    'app/Interfaces/Web/View/property/presentation.phtml' => ['tn-presentation-hero', 'data-copy-value'],
    'app/Interfaces/Web/View/spatial/scene.phtml' => ['tn-spatial-public', "partial('shared/spatial_viewer'"],
    'app/Interfaces/Web/View/error/failure.phtml' => ['tn-failure', 'data-failure-code'],
];
foreach ($specializedContracts as $path => $markers) {
    $source = $read($path);
    foreach ($markers as $marker) {
        $contains($source, $marker, 'Specialized surface contract is incomplete for ' . $path . '.');
    }
}

$viewRoot = $root . '/app/Interfaces/Web/View';
$globalForbidden = [
    'tn-page-hero',
    'tn-listing-hero',
    'tn-admin-card',
    'tn-admin-panel',
    'tn-portal-hero',
    'tn-auth-copy',
    'class="tn-sales-cta"',
    'tn-section-heading',
];
$breadcrumbWhitelist = [
    'property/catalog.phtml',
    'property/presentation.phtml',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($viewRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'phtml') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($viewRoot) + 1));
    $source = (string) file_get_contents($file->getPathname());

    foreach ($globalForbidden as $legacyMarker) {
        if (str_contains($source, $legacyMarker)) {
            throw new RuntimeException('Legacy shell marker ' . $legacyMarker . ' remains in production view ' . $relative);
        }
    }

    if (str_contains($source, 'tn-breadcrumbs') && !in_array($relative, $breadcrumbWhitelist, true)) {
        throw new RuntimeException('Unclassified breadcrumb shell remains in production view ' . $relative);
    }
}

foreach ([
    'frontend/entrypoints/property-workspace.js',
    'frontend/features/property/workspace.css',
    'frontend/features/property/workspace.js',
] as $retiredPropertyWorkspace) {
    if (is_file($root . '/' . $retiredPropertyWorkspace)) {
        throw new RuntimeException('Retired Property workspace frontend restored: ' . $retiredPropertyWorkspace);
    }
}

$docs = $read('docs/03-architecture/cos-remaining-shell-closure.md');
foreach ([
    '# Закриття залишкових UI shells',
    '## Хвиля 1',
    '### Architecture Explorer',
    '## Хвиля 2',
    '### Публічні контентні surfaces',
    '## Хвиля 3',
    '### Вхід та реєстрація',
    '### Нативний кабінет',
    '## Хвиля 4',
    '### Фінальний audit і classification',
    '### Виведені historical renderers',
    '### Whitelist спеціалізованих surfaces (`Specialized Surface Whitelist`)',
    '## Критерії завершення',
] as $marker) {
    $contains($docs, $marker, 'PHASE 14 documentation is incomplete.');
}

echo "PHASE 14 remaining shell closure passed.\n";
