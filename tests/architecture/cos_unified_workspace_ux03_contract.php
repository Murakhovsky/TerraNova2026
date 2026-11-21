<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$get=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$source=$get('symfony/src/Web/Experience/Unified/UnifiedBusinessWorkspaceController.php');
$twig=$get('symfony/templates/experience/unified/business.html.twig');
foreach (['WorkspaceCompositionResolver','WebExtensionCatalog','EntityLinkResolver',
          'TenantPermissions::MANAGE','$hit->entity->type!==$entityType','selectedKey',
          'activityAvailable','no-store, private','SessionCsrfValidator',
          'RelatedEntityReadInterface','BusinessEntitySummaryReadInterface',
          'cos_uw_saved_views_v1','savedViewBucket'] as $marker) {
    if (!str_contains($source,$marker)) throw new RuntimeException('UX03 missing: '.$marker);
}
foreach (['PDO','Doctrine\\','SELECT ','INSERT ','UPDATE ','/api/v1/','new UIAction('] as $bad) {
    if (str_contains($source,$bad)) throw new RuntimeException('UX03 forbidden direct dependency: '.$bad);
}
if (!str_contains($twig,'data-cos-unified-business')
    || !str_contains($twig,'data-cos-business-results')
    || !str_contains($twig,"workspace.hasSlot('sidebar')")
    || !str_contains($get('symfony/config/routes.yaml'),'cos_unified_business_workspace:')) {
    throw new RuntimeException('UX03 provider workspace template or route unavailable');
}
echo "UX03 canonical provider Workspace contract PASS.\n";
