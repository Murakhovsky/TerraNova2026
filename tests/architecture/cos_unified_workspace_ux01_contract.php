<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$get=static fn(string $p):string=>(string)file_get_contents($root.'/'.$p);
$source=$get('symfony/src/Web/Experience/Unified/UnifiedWorkspaceController.php');
$view=$get('symfony/templates/experience/unified/home.html.twig');
foreach (['TenantPermissions::MANAGE','WebExtensionCatalog','GlobalSearchService','WorkspaceShellFactory','no-store, private'] as $needle) {
    if (!str_contains($source,$needle)) throw new RuntimeException('UX01 boundary missing: '.$needle);
}
foreach (['PDO','Doctrine\\','SELECT ','INSERT ','/api/v1/'] as $forbidden) {
    if (str_contains($source,$forbidden)) throw new RuntimeException('UX01 bypass: '.$forbidden);
}
if (!str_contains($view,"extends 'experience/workspace_shell.html.twig'")
 || !str_contains($view,'data-cos-provider-workspaces')
 || !str_contains($get('symfony/config/routes.yaml'),'cos_unified_home:')
 || !str_contains($get('symfony/config/services.yaml'),'App\\Web\\Experience\\Unified\\UnifiedWorkspaceController:')) {
    throw new RuntimeException('UX01 Shell/route/DI contract incomplete.');
}
echo "UX01 canonical Workspace Shell contract PASS.\n";
