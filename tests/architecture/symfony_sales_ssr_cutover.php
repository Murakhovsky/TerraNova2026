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
    'app/Interfaces/Web/Controller/SalesController.php',
    'app/Interfaces/Web/Controller/SalesAdminController.php',
    'app/Interfaces/Web/Controller/SalesAdminTeamController.php',
    'app/Interfaces/Web/Controller/SalesAdminAgentController.php',
    'app/Interfaces/Web/Controller/SalesAdminPolicyController.php',
    'app/Interfaces/Web/Controller/SalesAdminHealthController.php',
    'app/Interfaces/Web/Controller/SalesAdminIntegrationController.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
    'app/Interfaces/Web/Routing/SalesTeamRoutes.php',
    'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesAdministrationRoutes.php',
    'app/Interfaces/Web/Routing/SalesModuleRouteContributor.php',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired Phalcon Sales SSR artifact restored: ' . $path);
}

foreach ([
    'symfony/src/Web/Phtml/PhtmlRenderer.php',
    'symfony/src/Web/Phtml/UrlHelper.php',
    'symfony/src/Web/Phtml/RequestQueryAdapter.php',
    'symfony/src/Web/Phtml/ViteAssetManifest.php',
    'symfony/src/Web/Navigation/NavigationBuilder.php',
    'symfony/src/Web/Sales/SalesPageController.php',
    'symfony/src/Web/Sales/SalesAdminPageController.php',
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

$security = $read('symfony/config/packages/security.yaml');
$authenticator = $read('symfony/src/Security/LegacySessionAuthenticator.php');
$assert(str_contains($security, "|sales)(?:/|$)'"), 'Symfony firewall does not own Sales SSR paths.');
$assert(str_contains($security, "path: '^/sales(?:/|$)'"), 'Symfony Sales SSR access-control rule is missing.');
$assert(str_contains($authenticator, "str_starts_with(\$path, '/sales')"), 'Legacy session authenticator does not support Sales SSR.');
$assert(str_contains($authenticator, "RedirectResponse('/auth/login')"), 'Unauthenticated Sales SSR must redirect to login.');

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
    'cos_web_sales_admin:',
    'cos_web_sales_admin_pipelines_page:',
    'cos_web_sales_admin_pipeline_page:',
    'cos_web_sales_admin_rules_page:',
    'cos_web_sales_admin_rule_page:',
    'cos_web_sales_admin_agents_page:',
    'cos_web_sales_admin_agent_page:',
    'cos_web_sales_admin_actions_page:',
    'cos_web_sales_admin_teams_page:',
    'cos_web_sales_admin_integrations_page:',
    'cos_web_sales_admin_health_page:',
] as $needle) {
    $assert(str_contains($routes, $needle), 'Canonical Symfony Sales page route is missing: ' . $needle);
}

$legacyFrontendRoutes = $read('app/Interfaces/Web/Routing/FrontendRoutes.php');
foreach (['/sales/dashboard', '/sales/pipeline', '/sales/today', '/sales/leads', '/sales/deals', '/sales/director', '/sales/admin'] as $path) {
    $assert(!str_contains($legacyFrontendRoutes, "'" . $path . "'"), 'Migrated Sales page route restored in Phalcon: ' . $path);
}
$salesManifest = $read('app/Domains/Sales/module.php');
$assert(!str_contains($salesManifest, "'salesRouteContributor'"), 'Sales manifest restored its retired Phalcon route contribution.');

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
