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

$homeController = $read('symfony/src/Controller/HomePageController.php');
foreach ([
    "renderer->render(\$request, 'home/canonical'",
    "'interfaceSurface' => 'public'",
    "'pageAssetEntries' => ['public-surface']",
] as $needle) {
    $contains($homeController, $needle, 'Homepage must use canonical Symfony Public runtime');
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
    'HomePageController',
    'path: /blog',
    'PublicContentPageController::blog',
    'path: /blog/{slug}',
    'PublicContentPageController::article',
    'path: /guide/{slug}',
    'PublicContentPageController::guide',
    'path: /property/catalog',
    'PropertyPageController::catalog',
    'path: /property/map',
    'PropertyPageController::map',
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

$home = $read('app/Interfaces/Web/View/home/canonical.phtml');
foreach (['tn-public-hero', '/auth/login', '/blog', '/api/v1/status'] as $needle) {
    $contains($home, $needle, 'Canonical Symfony home specialized marketing contract is incomplete');
}

foreach ([
    'app/Interfaces/Web/View/index/public.phtml',
] as $historical) {
    if (is_file($root . '/' . $historical)) {
        throw new RuntimeException('Historical Public renderer restored: ' . $historical);
    }
}

$map = $read('app/Interfaces/Web/View/property/map.phtml');
foreach (["['latitude']", "['longitude']", 'tn-map-canvas--geo'] as $needle) {
    $contains($map, $needle, 'Public map must project real geo coordinates');
}
foreach (['% 68', '% 58', '$index * 29', '$index * 23'] as $needle) {
    $notContains($map, $needle, 'Public map must not fabricate pin positions');
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
    'app/Interfaces/Web/View/property/map.phtml',
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
