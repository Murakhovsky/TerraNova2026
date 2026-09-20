<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'cos_web_content_admin:',
    'cos_web_content_admin_edit_new:',
    'cos_web_content_admin_edit:',
    'cos_web_content_admin_save:',
    'App\\Web\\Content\\ContentAdminPageController',
    '/admin/content/save/{id}',
] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Wave 14 route missing: ' . $needle);
}

$controllerPath = $root . '/symfony/src/Web/Content/ContentAdminPageController.php';
if (!is_file($controllerPath)) throw new RuntimeException('Wave 14 controller missing.');
$controller = (string) file_get_contents($controllerPath);
foreach ([
    'ContentServiceInterface','TenantContextProviderInterface','LegacySessionCsrfValidator',
    'LegacySessionReader','NavigationBuilder','->isManager()','->adminItems(','->revisions(','->save(',
] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Wave 14 controller contract missing: ' . $needle);
}
if (str_contains($controller, 'Interfaces\\Web')) throw new RuntimeException('Symfony controller depends on legacy Interfaces.');

$legacyRoutes = (string) file_get_contents($root . '/app/Interfaces/Web/Routing/FrontendRoutes.php');
if (str_contains($legacyRoutes, "'/admin/content")) throw new RuntimeException('Phalcon still owns /admin/content.');
if (is_file($root . '/app/Interfaces/Web/Controller/ContentController.php')) throw new RuntimeException('Legacy ContentController still exists.');

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
foreach (['admin/(?:diagnostics|content)', "path: '^/admin/content(?:/|$)'", 'roles: IS_AUTHENTICATED_FULLY'] as $needle) {
    if (!str_contains($security, $needle)) throw new RuntimeException('Wave 14 security contract missing: ' . $needle);
}

$authenticator = (string) file_get_contents($root . '/symfony/src/Security/LegacySessionAuthenticator.php');
if (!str_contains($authenticator, 'str_starts_with($path, \'/admin/content\')')) {
    throw new RuntimeException('admin/content is outside legacy-session web authenticator bridge.');
}

$edit = (string) file_get_contents($root . '/app/Interfaces/Web/View/content/edit.phtml');
if (!str_contains($edit, 'name="csrf_token"') || !str_contains($edit, '$csrfToken')) {
    throw new RuntimeException('Content form does not carry CSRF token.');
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
if (!str_contains($services, 'App\\Web\\Content\\ContentAdminPageController:')) {
    throw new RuntimeException('ContentAdminPageController service registration missing.');
}

foreach ([$root . '/deploy/configure-company-os-http.sh', $root . '/deploy/configure-dev-tls.sh'] as $proxyPath) {
    $proxy = (string) file_get_contents($proxyPath);
    foreach (['location = /admin/content {', 'location ^~ /admin/content/ {'] as $needle) {
        if (!str_contains($proxy, $needle)) throw new RuntimeException('Wave 14 proxy ownership missing: ' . $needle);
    }
}
echo "Wave 14 Content/Admin Symfony cutover contract passed.\n";
