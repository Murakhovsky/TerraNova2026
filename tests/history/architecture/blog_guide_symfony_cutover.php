<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$routes = $read('symfony/config/routes.yaml');
foreach ([
    'cos_web_blog_index:', 'path: /blog',
    'cos_web_blog_article:', 'path: /blog/{slug}',
    'cos_web_guide:', 'path: /guide/{slug}',
    'App\\Web\\Content\\PublicContentPageController',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Symfony public Content route contract missing: ' . $needle);
}

$controller = $read('symfony/src/Web/Content/PublicContentPageController.php');
foreach ([
    'ContentServiceInterface',
    'publicPosts($page)',
    'publicPost($slug)',
    'publicLanding($slug)',
    'getSchemeAndHttpHost()',
] as $needle) {
    $assert(str_contains($controller, $needle), 'Symfony public Content controller contract missing: ' . $needle);
}

$services = $read('symfony/config/services.yaml');
foreach ([
    'App\\Web\\Content\\PublicContentPageController:',
    'public: true',
    "tags: ['controller.service_arguments']",
] as $needle) {
    $assert(str_contains($services, $needle), 'Symfony public Content controller service wiring missing: ' . $needle);
}

$legacyRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach (["'/blog'", "'/blog/{slug:", "'/guide/{slug:"] as $needle) {
    $assert(!str_contains($legacyRoutes, $needle), 'Phalcon still owns public Content route: ' . $needle);
}
$assert(!is_file($root . '/app/Interfaces/Web/Controller/BlogController.php'), 'Retired Phalcon BlogController was restored.');

foreach (['deploy/configure-company-os-http.sh', 'deploy/configure-dev-tls.sh'] as $path) {
    $proxy = $read($path);
    foreach (['location = /blog {', 'location ^~ /blog/ {', 'location ^~ /guide/ {'] as $needle) {
        $assert(str_contains($proxy, $needle), 'Host proxy public Content ownership missing in ' . $path . ': ' . $needle);
    }
}

echo "Symfony Blog / Guide SSR cutover boundary OK\n";
