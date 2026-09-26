<?php
declare(strict_types=1);

use Interfaces\Web\Navigation\FrontendNavigation;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('WEB V0.10 artifact is missing: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};
$contains = static function (string $source, string $needle, string $message): void {
    if (!str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$notContains = static function (string $source, string $needle, string $message): void {
    if (str_contains($source, $needle)) throw new RuntimeException($message . ': ' . $needle);
};
$keys = static fn(array $items): array => array_map(static fn(array $item): string => (string) ($item['key'] ?? ''), $items);

foreach (['app/Domains/Public', 'app/Domains/Frontend'] as $forbiddenDomain) {
    if (is_dir($root . '/' . $forbiddenDomain)) {
        throw new RuntimeException('Public/Frontend are interface surfaces, not DDD domains: ' . $forbiddenDomain);
    }
}

if ($keys(FrontendNavigation::public()) !== ['catalog', 'services', 'partners', 'about', 'cos']) {
    throw new RuntimeException('Public main navigation must remain canonical and compact.');
}

$homeController = $read('symfony/src/Web/PublicSite/HomeController.php');
foreach ([
    'PageArchetype::PublicDetailMarketing',
    'PagePresentationFactory',
    "experience/public/home.html.twig",
] as $needle) {
    $contains($homeController, $needle, 'Homepage must use the Wave 13 canonical Public runtime');
}
foreach (['PhtmlRenderer', 'ViteAssetManifest', 'public-surface'] as $legacy) {
    $notContains($homeController, $legacy, 'Homepage must not restore PHTML/Vite ownership');
}

$contentController = $read('symfony/src/Web/Content/PublicContentPageController.php');
foreach ([
    "public function blog(Request \$request): Response",
    "public function article(Request \$request, string \$slug): Response",
    "public function guide(Request \$request, string \$slug): Response",
    "'blog/index'",
    "'blog/show'",
    "'blog/landing'",
    "'interfaceSurface' => 'public'",
] as $needle) {
    $contains($contentController, $needle, 'Public Content Symfony delivery contract is incomplete');
}

$propertyController = $read('symfony/src/Web/Property/PropertyPageController.php');
foreach ([
    'public function catalog(Request $request): Response',
    'public function map(Request $request): Response',
    'public function favour(Request $request): Response',
    'public function show(Request $request, string $slug): Response',
    'public function presentation(Request $request, string $slug): Response',
    'public function submit(Request $request): Response',
    "'property/catalog'",
    "'property/map'",
    "'property/favour'",
    "'property/show'",
    "'property/presentation'",
    "'property/submit'",
] as $needle) {
    $contains($propertyController, $needle, 'Public Property Symfony delivery contract is incomplete');
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'path: /',
    'App\Web\PublicSite\HomeController',
    'path: /blog',
    'PublicContentPageController::blog',
    'path: /blog/{slug}',
    'PublicContentPageController::article',
    'path: /guide/{slug}',
    'PublicContentPageController::guide',
    'path: /property/catalog',
    'PropertyPageController::catalog',
    'path: /property/map',
    'PropertyMapController::index',
    'path: /property/favour',
    'PropertyPageController::favour',
    'path: /property/show/{slug}',
    'PropertyPageController::show',
    'path: /property/presentation/{slug}',
    'PropertyPageController::presentation',
    'path: /property/submit',
    'PropertyPageController::submit',
] as $needle) {
    $contains($routes, $needle, 'Canonical Public route is missing');
}

$home = $read('symfony/templates/experience/public/home.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosCard',
    'Company Operating System',
    '/auth/login',
    '/blog',
    '/api/v1/status',
    'data-cos-public="home"',
] as $needle) {
    $contains($home, $needle, 'Wave 13 Public home composition is incomplete');
}
foreach (['tn-', 'style=', '<script'] as $legacy) {
    $notContains($home, $legacy, 'Wave 13 Public home restored legacy/local presentation');
}
if (is_file($root . '/app/Interfaces/Web/View/home/canonical.phtml')) {
    throw new RuntimeException('Retired home PHTML restored.');
}

foreach ([
    'app/Interfaces/Web/View/index/public.phtml',
] as $historical) {
    if (is_file($root . '/' . $historical)) {
        throw new RuntimeException('Historical Public renderer restored: ' . $historical);
    }
}

$map = $read('symfony/templates/experience/property/map.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    '<twig:CosContextPanel',
    'data-controller="property-map"',
    'data-x="{{ point.x }}"',
    'data-y="{{ point.y }}"',
] as $needle) {
    $contains($map, $needle, 'Public map must use canonical Map / Spatial composition.');
}
foreach (['tn-', 'style=', '<script'] as $legacy) {
    $notContains($map, $legacy, 'Public map must not restore legacy/local presentation.');
}
if (is_file($root . '/app/Interfaces/Web/View/property/map.phtml')) {
    throw new RuntimeException('Retired public Property map PHTML restored.');
}
$mapAdapter=$read('symfony/assets/controllers/property_map_controller.js');
foreach(['dataset.x','dataset.y','pin.style.left','pin.style.top'] as $needle){
    $contains($mapAdapter,$needle,'Public map DOM positioning adapter incomplete.');
}

$header = $read('app/Interfaces/Web/View/shared/public_header.phtml');
$footer = $read('app/Interfaces/Web/View/shared/public_footer.phtml');
$contains($header, '$publicNavigation', 'Public header must consume canonical navigation through its view model');
$notContains($header, 'FrontendNavigation::public()', 'Public header must not construct navigation inside the template');
$notContains($header, 'getDI()', 'Public header must remain container-free');
$contains($header, 'data-interface-surface="public"', 'Public header must expose the surface marker');
$contains($header, "partial('components/ui/action_bar'", 'Public header actions must use canonical ActionBar');
$notContains($header, 'class="tn-btn ', 'Public header must not render legacy tn-btn actions');
$notContains($header, "$action['class']", 'Public header must not consume presentation class descriptors');

foreach ([
    'app/Interfaces/Web/View/page/show.phtml',
    'app/Interfaces/Web/View/blog/show.phtml',
    'app/Interfaces/Web/View/blog/index.phtml',
    'app/Interfaces/Web/View/blog/landing.phtml',
    'app/Interfaces/Web/View/auth/login.phtml',
    'app/Interfaces/Web/View/auth/register.phtml',
    'app/Interfaces/Web/View/property/seo.phtml',
    'app/Interfaces/Web/View/property/submit.phtml',
    'app/Interfaces/Web/View/property/presentation.phtml',
    'app/Interfaces/Web/View/property/show.phtml',
    'app/Interfaces/Web/View/property/catalog.phtml',
] as $publicHeaderCaller) {
    $caller = $read($publicHeaderCaller);
    $contains($caller, "partial('shared/public_header'", 'Public header caller contract is missing');
    $notContains($caller, "'class' => 'tn-btn--", 'Public header caller must use semantic action variants');
}
foreach (['property/catalog', 'services', 'partners', 'terra-nova', 'cos/en'] as $needle) {
    $contains($footer, $needle, 'Public footer is missing a canonical destination');
}

foreach (['frontend/entrypoints/public-surface.js', 'frontend/features/public/surface.css', 'frontend/features/public/surface.js'] as $asset) {
    $read($asset);
}
$vite = $read('vite.config.js');
$contains($vite, "'public-surface':", 'Public surface entrypoint must be managed by Vite');
$browser = $read('frontend/features/public/surface.js');
foreach (['role', 'permission', 'deal_status', 'localStorage', 'sessionStorage'] as $forbidden) {
    $notContains($browser, $forbidden, 'Public browser code must remain presentation-only');
}

echo "WEB V0.10 native Public Surface architecture passed.\n";
