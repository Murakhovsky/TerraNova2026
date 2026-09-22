<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_sitemap:',
    'path: /sitemap.xml',
    'App\\Web\\Seo\\SitemapController',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Symfony sitemap route contract missing: ' . $needle);
}

$assert(str_contains($routes, 'path: /{slug}'), 'Static PublicPageCatalog pages must have a canonical Symfony route.');
$assert(str_contains($routes, "slug: 'terra-nova|agency|services|partners|team|cases|vacancies|contacts|it|art|cos'"), 'Static PublicPageCatalog route allowlist is missing.');
$assert(str_contains($routes, 'PublicContentPageController::page'), 'Static PublicPageCatalog pages must be delivered by PublicContentPageController.');

$services = $read('symfony/config/services.yaml');
foreach ([
    'App\\Web\\Seo\\SitemapController:',
    'public: true',
    "tags: ['controller.service_arguments']",
    '$organizationId: \'%env(COS_ORGANIZATION_ID)%\'',
] as $needle) {
    $assert(str_contains($services, $needle), 'Symfony sitemap controller service wiring missing: ' . $needle);
}

$controller = $read('symfony/src/Web/Seo/SitemapController.php');
foreach ([
    'PublicPropertyReadRepositoryInterface',
    'ContentServiceInterface',
    'PublicPageCatalog',
    'sitemapTypes',
    'sitemapLocations',
    'sitemapLandingPairs',
    'sitemapProperties',
    'sitemapItems',
    'getSchemeAndHttpHost',
    'application/xml; charset=UTF-8',
] as $needle) {
    $assert(str_contains($controller, $needle), 'Symfony sitemap controller contract missing: ' . $needle);
}

$repository = $read('app/Domains/Property/Infrastructure/ReadModel/MySql/MysqlPublicPropertyReadRepository.php');
foreach ([
    'public function sitemapTypes',
    'public function sitemapLocations',
    'public function sitemapLandingPairs',
    'public function sitemapProperties',
    'p.organization_id = :organization_id',
    'p.visibility = "public"',
    'p.status IN ("published", "active")',
] as $needle) {
    $assert(str_contains($repository, $needle), 'Public sitemap Property boundary missing: ' . $needle);
}

$legacyRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
$assert(!str_contains($legacyRoutes, "'/sitemap.xml'"), 'Phalcon still owns /sitemap.xml.');
$assert(!is_file($root . '/app/Interfaces/Web/Controller/SeoController.php'), 'Retired Phalcon SeoController was restored.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    $assert(str_contains($proxy, 'location = /sitemap.xml {'), 'Host proxy does not route sitemap.xml through Symfony: ' . $path);
}

echo "Symfony sitemap.xml cutover boundary OK\n";
