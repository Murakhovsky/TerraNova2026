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
    if (!str_contains($routes, $needle)) throw new RuntimeException('Content/Admin route missing: ' . $needle);
}

$controllerPath = $root . '/symfony/src/Web/Content/ContentAdminPageController.php';
if (!is_file($controllerPath)) throw new RuntimeException('Content/Admin controller missing.');
$controller = (string) file_get_contents($controllerPath);
foreach ([
    'GetContentAdministrationQuery',
    'GetContentEditorQuery',
    'SaveContentCommand',
    'QueryBusInterface',
    'CommandBusInterface',
    'TenantContextProviderInterface',
    'SessionCsrfValidator',
    'WorkspaceShellFactory',
    'PagePresentationFactory',
    'PageArchetype::SystemControlSurface',
    'PageArchetype::FormEditor',
] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Content/Admin controller contract missing: ' . $needle);
}
foreach (['PhtmlRenderer','NavigationBuilder','ContentServiceInterface','Interfaces\\Web'] as $forbidden) {
    if (str_contains($controller, $forbidden)) throw new RuntimeException('Content/Admin Web controller leaked retired/direct dependency: ' . $forbidden);
}

$manage=(string)file_get_contents($root.'/symfony/templates/experience/content/manage.html.twig');
$edit=(string)file_get_contents($root.'/symfony/templates/experience/content/edit.html.twig');
foreach(['<twig:CosPageHeader','<twig:CosToolbar','<twig:CosFilterBar','<twig:CosDataGrid'] as $needle){
    if(!str_contains($manage,$needle)) throw new RuntimeException('Content management Twig missing: '.$needle);
}
foreach(['class="cos-form"','class="cos-form__section"','class="cos-form__actions"','name="csrf_token"'] as $needle){
    if(!str_contains($edit,$needle)) throw new RuntimeException('Content editor Twig missing: '.$needle);
}
foreach([$manage,$edit] as $surface){
    foreach(['tn-','style=','<script'] as $forbidden){
        if(str_contains($surface,$forbidden)) throw new RuntimeException('Content Twig restored legacy/local presentation: '.$forbidden);
    }
}

foreach([
    'app/Interfaces/Web/View/content/manage.phtml',
    'app/Interfaces/Web/View/content/edit.phtml',
] as $legacy){
    if(file_exists($root.'/'.$legacy)) throw new RuntimeException('Legacy Content PHTML restored: '.$legacy);
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
foreach (['admin/(?:diagnostics|content)', "path: '^/admin/content(?:/|$)'", 'roles: IS_AUTHENTICATED_FULLY'] as $needle) {
    if (!str_contains($security, $needle)) throw new RuntimeException('Content security contract missing: ' . $needle);
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
if (!str_contains($services, 'App\\Web\\Content\\ContentAdminPageController:')) {
    throw new RuntimeException('ContentAdminPageController service registration missing.');
}

foreach ([$root . '/deploy/configure-company-os-http.sh', $root . '/deploy/configure-dev-tls.sh'] as $proxyPath) {
    $proxy = (string) file_get_contents($proxyPath);
    foreach (['location = /admin/content {', 'location ^~ /admin/content/ {'] as $needle) {
        if (!str_contains($proxy, $needle)) throw new RuntimeException('Content proxy ownership missing: ' . $needle);
    }
}
echo "Wave 13 Content/Admin Symfony/Twig cutover contract passed.\n";
