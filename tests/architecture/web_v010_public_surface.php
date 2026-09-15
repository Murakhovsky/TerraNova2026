<?php

declare(strict_types=1);

use Interfaces\Web\Navigation\FrontendNavigation;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static function (string $path) use ($root): string {
    $full = $root . '/' . $path;
    if (!is_file($full)) {
        throw new RuntimeException('WEB V0.10 artifact is missing: ' . $path);
    }
    return (string) file_get_contents($full);
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

$indexController = $read('app/Interfaces/Web/Controller/IndexController.php');
$contains($indexController, "pick('index/public')", 'Homepage must use the canonical Public view.');
$contains($indexController, "interfaceSurface = 'public'", 'Homepage must declare Public surface ownership.');
$contains($indexController, "['public-surface']", 'Homepage must load the Public surface bundle.');

$pageController = $read('app/Interfaces/Web/Controller/PageController.php');
$contains($pageController, "interfaceSurface = 'public'", 'Content pages must declare Public surface ownership.');
$contains($pageController, "['public-surface']", 'Content pages must load the Public surface bundle.');

$publicController = $read('app/Interfaces/Web/Controller/PublicPropertyController.php');
foreach (['catalogAction', 'mapAction', 'showAction', 'presentationAction', 'submitAction', "interfaceSurface = 'public'", "'public-surface'"] as $needle) {
    $contains($publicController, $needle, 'Public Property delivery contract is incomplete.');
}
foreach (['clientCaseService', 'managerClientCases', 'requireManager', 'isManager', 'workspaceSection', "interfaceSurface = 'workspace'"] as $needle) {
    $notContains($publicController, $needle, 'Public Property controller leaked Workspace/CRM behaviour.');
}

$routes = $read('app/Interfaces/Web/Routing/PublicPropertyRoutes.php');
foreach (['/property/catalog', '/property/map', '/property/show/{slug:[a-z0-9-]+}', '/property/presentation/{slug:[a-z0-9-]+}', '/property/submit', "'controller' => 'public_property'"] as $needle) {
    $contains($routes, $needle, 'Canonical Public Property route is missing.');
}
$contributor = $read('app/Interfaces/Web/Routing/PropertyModuleRouteContributor.php');
$contains($contributor, 'PublicPropertyRoutes::register($router)', 'Property module must register its Public projection routes.');

$home = $read('app/Interfaces/Web/View/index/public.phtml');
foreach (["partial('shared/public_header'", "partial('shared/public_footer'", 'featuredProperties', 'property/catalog', 'property/map'] as $needle) {
    $contains($home, $needle, 'Public homepage composition is incomplete.');
}
foreach (['property/listing', 'tn-visual-header', 'tn-visual-footer'] as $needle) {
    $notContains($home, $needle, 'Public homepage restored a private or local shell dependency.');
}

$map = $read('app/Interfaces/Web/View/property/map.phtml');
foreach (["['latitude']", "['longitude']", 'tn-map-canvas--geo'] as $needle) {
    $contains($map, $needle, 'Public map must project real geo coordinates.');
}
foreach (['% 68', '% 58', '$index * 29', '$index * 23'] as $needle) {
    $notContains($map, $needle, 'Public map must not fabricate pin positions.');
}

$header = $read('app/Interfaces/Web/View/shared/public_header.phtml');
$footer = $read('app/Interfaces/Web/View/shared/public_footer.phtml');
$contains($header, 'FrontendNavigation::public()', 'Public header must use canonical navigation.');
$contains($header, 'data-interface-surface="public"', 'Public header must expose the surface marker.');
foreach (['property/catalog', 'services', 'partners', 'terra-nova', 'cos/en'] as $needle) {
    $contains($footer, $needle, 'Public footer is missing a canonical destination.');
}

foreach (['frontend/entrypoints/public-surface.js', 'frontend/features/public/surface.css', 'frontend/features/public/surface.js'] as $path) {
    $read($path);
}
$vite = $read('vite.config.js');
$contains($vite, "'public-surface':", 'Public surface entrypoint must be managed by Vite.');
$browser = $read('frontend/features/public/surface.js');
foreach (['role', 'permission', 'deal_status', 'localStorage', 'sessionStorage'] as $forbidden) {
    $notContains($browser, $forbidden, 'Public browser code must remain presentation-only.');
}

$router = new Phalcon\Mvc\Router(false);
$router->setDI(new Phalcon\Di\FactoryDefault());
Interfaces\Web\Routing\FrontendRoutes::register($router, []);
(new Interfaces\Web\Routing\PropertyModuleRouteContributor())->register($router);
foreach ([
    '/property/catalog' => ['public_property', 'catalog'],
    '/property/map' => ['public_property', 'map'],
    '/property/show/test-object' => ['public_property', 'show'],
    '/property/presentation/test-object' => ['public_property', 'presentation'],
    '/property/submit' => ['public_property', 'submit'],
] as $path => [$controller, $action]) {
    $router->handle($path);
    if ($router->getControllerName() !== $controller || $router->getActionName() !== $action) {
        throw new RuntimeException('Public route ownership mismatch for ' . $path . '.');
    }
}

echo "WEB V0.10 Public Surface architecture passed.\n";
