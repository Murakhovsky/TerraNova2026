<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('symfony/src/Web/Experience/Unified/UnifiedCapabilityController.php');
$presenter=$read('symfony/src/Web/Experience/Unified/UnifiedCapabilityPresentation.php');
$view=$read('symfony/templates/experience/unified/capabilities.html.twig');
$routes=$read('symfony/config/routes.yaml');
foreach (['CanonicalCapabilityGateway','SessionCsrfValidator','TenantPermissions::MANAGE','trusted_read','property.asset.search','property.asset.get'] as $token) {
    if (!str_contains($controller,$token)) throw new RuntimeException('UX02 missing: '.$token);
}
foreach (['PDO','Doctrine\\','SELECT ','INSERT ','new CreatePropertyCommand','fetch('] as $bad) {
    if (str_contains($controller,$bad)) throw new RuntimeException('UX02 bypass: '.$bad);
}
if (!str_contains($presenter,'PRESENTERS')
    || !str_contains($presenter,"'execution_verified'=>false")
    || !str_contains($view,"path('cos_capabilities_property_create')")
    || !str_contains($routes,'cos_unified_actions:')
    || !str_contains($routes,'cos_unified_property_query:')) {
    throw new RuntimeException('UX02 presentation and canonical form integration incomplete.');
}
echo "UX02 canonical Query/Action presentation contract PASS.\n";
