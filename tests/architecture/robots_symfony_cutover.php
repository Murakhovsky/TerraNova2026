<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_robots:',
    'path: /robots.txt',
    'App\\Web\\Seo\\RobotsController',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Symfony robots route contract missing: ' . $needle);
}

$controller = $read('symfony/src/Web/Seo/RobotsController.php');
foreach ([
    'User-agent: *',
    'Disallow: /admin',
    'Disallow: /auth',
    'Disallow: /cabinet',
    'Disallow: /client-case',
    'Disallow: /property/manage',
    "getSchemeAndHttpHost() . '/sitemap.xml'",
    "'Content-Type' => 'text/plain; charset=UTF-8'",
] as $needle) {
    $assert(str_contains($controller, $needle), 'Symfony robots response contract missing: ' . $needle);
}

$legacyRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
$assert(!str_contains($legacyRoutes, "'/robots.txt'"), 'Phalcon still owns /robots.txt.');

$legacySeo = $read('app/Interfaces/Web/Controller/SeoController.php');
$assert(!str_contains($legacySeo, 'robotsAction'), 'Retired Phalcon robots action was restored.');
$assert(str_contains($legacySeo, 'sitemapAction'), 'Sitemap must remain on Phalcon until its separate cutover.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    $assert(str_contains($proxy, 'location = /robots.txt {'), 'Host proxy does not route robots.txt through Symfony: ' . $path);
    $assert(str_contains($proxy, 'proxy_pass http://$SYMFONY_UPSTREAM;'), 'Symfony upstream missing from robots proxy: ' . $path);
}

echo "Symfony robots.txt cutover boundary OK\n";
