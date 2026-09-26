<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) {
        throw new RuntimeException('Wave 13 Public Brand artifact is missing: ' . $path);
    }

    $content = file_get_contents($full);
    if ($content === false) {
        throw new RuntimeException('Unable to read: ' . $path);
    }

    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($message . ' Missing: ' . $needle);
    }
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) {
        throw new RuntimeException($message . ' Forbidden: ' . $needle);
    }
};

$query = $read('symfony/src/Application/Content/Query/GetPublicBrandPageQuery.php');
$handler = $read('symfony/src/Application/Content/Query/GetPublicBrandPageQueryHandler.php');
foreach (['QueryInterface', 'public string $slug'] as $marker) {
    $contains($query, $marker, 'Public Brand Query contract is incomplete.');
}
foreach (['QueryHandlerInterface', 'PublicPageCatalog', 'return $this->pages->page($query->slug)'] as $marker) {
    $contains($handler, $marker, 'Public Brand Query handler contract is incomplete.');
}

$controller = $read('symfony/src/Web/PublicSite/PublicBrandController.php');
foreach ([
    'GetPublicBrandPageQuery',
    'ReceivePublicLeadCommand',
    'QueryBusInterface',
    'CommandBusInterface',
    'PageArchetype::PublicDetailMarketing',
    "['PageHeader', 'ActionBar']",
    "experience/public/brand_page.html.twig",
    "OrganizationId::fromString(\$this->organizationId)",
] as $marker) {
    $contains($controller, $marker, 'Public Brand controller contract is incomplete.');
}
foreach (['PhtmlRenderer', 'SalesWriteServiceFactoryInterface', 'PublicPageCatalog $'] as $forbidden) {
    $notContains($controller, $forbidden, 'Public Brand controller must stay on the Application boundary.');
}

$presenter = $read('symfony/src/Web/PublicSite/PublicBrandPresenter.php');
$viewModel = $read('symfony/src/Web/PublicSite/ViewModel/PublicBrandViewModel.php');
foreach (['PublicBrandViewModel', 'sections:', 'hasForm:', 'noticeTone:'] as $marker) {
    $contains($presenter, $marker, 'Public Brand presenter contract is incomplete.');
}
foreach (['final readonly class PublicBrandViewModel', 'canonicalPath()', 'state()'] as $marker) {
    $contains($viewModel, $marker, 'Public Brand ViewModel contract is incomplete.');
}

$template = $read('symfony/templates/experience/public/brand_page.html.twig');
foreach ([
    "{% extends 'experience/public_shell.html.twig' %}",
    '<twig:CosPageHeader',
    '<twig:CosCard',
    '<twig:CosActionBar',
    '<twig:CosFormSection',
    '<twig:CosStickyActions',
    'data-cos-public="brand"',
    'data-cos-public-brand="{{ brand.slug }}"',
    'action="/contacts#request"',
] as $marker) {
    $contains($template, $marker, 'Public Brand Twig composition is incomplete.');
}
foreach (['tn-', 'style=', 'onclick=', '<script'] as $forbidden) {
    $notContains($template, $forbidden, 'Public Brand Twig must not introduce legacy/local presentation.');
}

$routes = $read('symfony/config/routes.yaml');
$expectedRoutes = [
    '/terra-nova' => 'terra-nova',
    '/agency' => 'agency',
    '/services' => 'services',
    '/partners' => 'partners',
    '/team' => 'team',
    '/cases' => 'cases',
    '/vacancies' => 'vacancies',
    '/contacts' => 'contacts',
    '/it' => 'it',
    '/art' => 'art',
];
foreach ($expectedRoutes as $path => $slug) {
    $contains($routes, 'path: ' . $path, 'Public Brand route is missing.');
    $contains($routes, "defaults: { slug: '" . $slug . "' }", 'Public Brand route slug default is missing.');
}
$contains($routes, 'methods: [GET, HEAD, POST]', 'Contacts route must preserve inbound lead POST semantics.');

$services = $read('symfony/config/services.yaml');
foreach ([
    'App\\Web\\PublicSite\\PublicBrandController:',
    "\$organizationId: '%env(COS_ORGANIZATION_ID)%'",
] as $marker) {
    $contains($services, $marker, 'Public Brand service wiring is incomplete.');
}

$catalog = $read('app/Domains/Content/Application/Service/PublicPageCatalog.php');
foreach (array_values($expectedRoutes) as $slug) {
    $contains($catalog, "'" . $slug . "' => [", 'PublicPageCatalog definition is missing.');
}

if (is_file($root . '/app/Interfaces/Web/View/page/show.phtml')) {
    throw new RuntimeException('Legacy Public Brand page/show.phtml must be retired.');
}
if (is_file($root . '/symfony/assets/styles/domains/public-brand.css')) {
    throw new RuntimeException('Public Brand must not create a page-family CSS island.');
}

$tracker = $read('docs/03-architecture/wave13-migration-tracker.md');
foreach (range(33, 42) as $unit) {
    $contains($tracker, sprintf('VR-%03d', $unit), 'Public Brand migration tracker unit is missing.');
}
foreach ([
    '## Фаза 9 — Public Brand',
    '## Фаза 9 — завершення Public Brand',
    'legacy `app/Interfaces/Web/View/page/show.phtml` = **0**',
    'Next',
] as $marker) {
    if ($marker === 'Next') {
        continue;
    }
    $contains($tracker, $marker, 'Public Brand tracker closure is incomplete.');
}

echo "Wave 13 Public Brand migration passed.\n";
