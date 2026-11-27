<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string)file_get_contents($root.'/symfony/config/routes.yaml');
$ops = (string)file_get_contents($root.'/symfony/config/routes/ux05_operations.yaml');
$home = (string)file_get_contents($root.'/symfony/templates/experience/unified/home.html.twig');
$business = (string)file_get_contents($root.'/symfony/templates/experience/unified/business.html.twig');
$ai = (string)file_get_contents($root.'/symfony/src/Web/Experience/AI/AgentWorkspaceAccess.php');
$registrations = (string)file_get_contents($root.'/resources/experience/pages/unified.yaml');
$checks = [
    ['operations route', $ops, 'cos_ux05_operations:'],
    ['operations experience registry', $registrations, 'name: cos_ux05_operations'],
    ['goals route', $routes, 'cos_federation_goals_index:'],
    ['AI route', $routes, 'cos_web_ai_panel:'],
    ['unified home operations', $home, "path('cos_ux05_operations')"],
    ['unified home goals', $home, "path('cos_federation_goals_index')"],
    ['unified home AI', $home, "path('cos_web_ai_panel',{view:'workspace'})"],
    ['business operations', $business, "path('cos_ux05_operations')"],
    ['business AI scoped to workspace', $business, "path('cos_web_ai_panel',{view:'workspace',workspace:workspace.definition.id})"],
    ['AI tenant manager gate', $ai, 'TenantPermissions::MANAGE'],
];
foreach ($checks as [$label,$source,$needle]) {
    if (!str_contains($source,$needle)) throw new RuntimeException('CUW Wave 2 navigation contract missing: '.$label);
}
foreach (['en','uk'] as $locale) {
    $catalog = (string)file_get_contents($root.'/symfony/translations/messages.'.$locale.'.yaml');
    foreach (['uw.home.goals','uw.home.ai_workspace','uw.business.ai_workspace'] as $key) {
        if (!str_contains($catalog,'"'.$key.'":')) throw new RuntimeException('Missing '.$locale.' translation: '.$key);
    }
}
echo "CUW Wave 2 cross-workspace navigation, manager access and locale contract PASS.\\n";
