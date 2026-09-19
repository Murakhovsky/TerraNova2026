<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    'symfony/src/Web/Phtml/PhtmlRenderer.php',
    'symfony/src/Web/Phtml/UrlHelper.php',
    'symfony/src/Web/Phtml/RequestQueryAdapter.php',
    'symfony/src/Web/Phtml/ViteAssetManifest.php',
    'symfony/src/Web/Navigation/NavigationBuilder.php',
    'symfony/src/Web/Sales/SalesPageController.php',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Symfony Web layer depends on Phalcon: ' . $path);
}

$layout = $read('app/Interfaces/Web/View/index.phtml');
$assert(str_contains($layout, '$this->assets($assetEntries)'), 'Global PHTML layout is not bound to the framework-neutral asset helper.');
$assert(!str_contains($layout, "di('viteAssetManifest')"), 'Global PHTML layout still reads the legacy DI container.');

foreach ([
    'app/Interfaces/Web/View/shared/manager_header.phtml',
    'app/Interfaces/Web/View/shared/portal_header.phtml',
    'app/Interfaces/Web/View/components/sales/navigation.phtml',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'getDI()'), 'PHTML template still uses a service locator: ' . $path);
    $assert(!str_contains($source, 'di('), 'PHTML template still uses the legacy DI helper: ' . $path);
}

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_sales_root:',
    'cos_web_sales_dashboard:',
    'cos_web_sales_today:',
    'cos_web_sales_pipeline:',
    'cos_web_sales_leads:',
    'cos_web_sales_deals:',
    'cos_web_sales_deal:',
    'cos_web_sales_director:',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Canonical Symfony Sales page route is missing: ' . $needle);
}

$phpImage = $read('docker/symfony/php/Dockerfile');
$nginxImage = $read('docker/symfony/nginx/Dockerfile');
$nginx = $read('docker/symfony/nginx/default.conf');
$assert(str_contains($phpImage, 'COPY app/Interfaces/Web/View/'), 'Symfony PHP image does not contain PHTML views.');
$assert(str_contains($phpImage, 'COPY public/build/'), 'Symfony PHP image does not contain the Vite manifest/build.');
$assert(str_contains($nginxImage, 'COPY public/build/'), 'Symfony nginx image does not contain browser assets.');
$assert(str_contains($nginx, 'location ^~ /build/'), 'Symfony nginx does not serve immutable Vite assets.');

foreach ([
    'deploy/configure-company-os-http.sh',
    'deploy/configure-dev-tls.sh',
] as $path) {
    $proxy = $read($path);
    $assert(str_contains($proxy, 'location = /sales {'), 'Host proxy does not route /sales to Symfony: ' . $path);
    $assert(str_contains($proxy, 'location ^~ /sales/ {'), 'Host proxy does not route /sales/* to Symfony: ' . $path);
    $assert(str_contains($proxy, 'location ^~ /build/ {'), 'Host proxy does not route Vite assets to Symfony: ' . $path);
}

echo "Symfony Sales SSR cutover boundary OK\n";
