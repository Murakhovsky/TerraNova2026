<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.10.0','>='),'Growth manifest must remain V0.10+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.8.0','>='),'Growth schema must remain V0.8+.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.10 must remain disabled before tenant cutover.');
$assert(in_array('growth.api.v1',$manifest['contributions']['capabilities']??[],true),'Growth V0.10 API capability is missing.');
$lifecycleMigration='app/migrations/20260922_000074_growth_v0100_api_surface.sql';
$assert(in_array($lifecycleMigration,$manifest['contributions']['migration_files']??[],true),'Growth V0.10 lifecycle migration is missing.');
$lifecycleSql=$read($lifecycleMigration);
foreach(["installed_version='0.10.0'","schema_version='0.8.0'","installed_version IN ('0.8.0','0.9.0')"] as $needle){
    $assert(str_contains($lifecycleSql,$needle),'Growth V0.10 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($lifecycleSql),$forbidden),'Growth V0.10 lifecycle migration must not change Growth schema: '.$forbidden);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach([
    'GrowthApplicationBoundary','GrowthSignalCollectorBoundary','GrowthIntelligenceBoundary','GrowthBuyingCommitteeBoundary',
    'GrowthResearchBoundary','GrowthDecisionBoundary','GrowthHandoffBoundary',
    'TenantContextProviderInterface','SessionCsrfValidator','ActiveModuleResolver',
    'TenantPermissions::ACCESS','TenantPermissions::MANAGE',
    "isEnabled(\$tenant->organizationId()->value(),'growth')",
    "X-Idempotency-Key",'_cos_correlation_id',
] as $needle){
    $assert(str_contains($controller,$needle),'Growth API controller missing: '.$needle);
}
foreach([
    'PDO','GrowthRepositoryInterface','GrowthIntelligenceRepositoryInterface',
    'GrowthBuyingCommitteeRepositoryInterface','Infrastructure\\Persistence','Domains\\Sales\\',
] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Growth API controller crossed boundary: '.$forbidden);
}

$requiredMethods=[
    'collectors','runCollector','createSignal','signal','createCandidate','candidate','researchCandidate','scoreCandidate',
    'qualifyCandidate','monitorCandidate','disqualifyCandidate','prepareHandoff',
    'createIcp','reviseIcp','activateIcp','createAccount','account','snapshotAccount','scoreAccount',
    'createContact','snapshotContact','assessCommittee','committee',
    'generateResearch','acceptResearch','researchBrief',
    'createQualificationPolicy','reviseQualificationPolicy','activateQualificationPolicy',
    'evaluateCandidate','decisionBrief','handoffTargets','handoffBrief','dispatchHandoff',
];
foreach($requiredMethods as $method){
    $assert(str_contains($controller,'function '.$method.'('),'Growth API controller method missing: '.$method);
}

$routes=$read('symfony/config/routes.yaml');
preg_match_all('/^cos_api_v1_growth_[a-z0-9_]+:/m',$routes,$matches);
$assert(count($matches[0])>=34,'Growth V0.10 canonical API surface must not shrink below 34 routes.');
foreach([
    '/api/v1/growth/collectors',
    '/api/v1/growth/collectors/{name}/run',
    '/api/v1/growth/signals',
    '/api/v1/growth/candidates',
    '/api/v1/growth/icp',
    '/api/v1/growth/accounts',
    '/api/v1/growth/candidates/{id}/research/proposals',
    '/api/v1/growth/qualification-policies',
    '/api/v1/growth/candidates/{id}/evaluate',
    '/api/v1/growth/handoff/targets',
    '/api/v1/growth/candidates/{id}/handoff/dispatch',
] as $path){
    $assert(str_contains($routes,'path: '.$path),'Growth canonical route missing: '.$path);
}
$assert(substr_count($routes,'App\\Http\\Api\\V1\\Controller\\GrowthApiController::')>=34,'Growth API controller route ownership must not shrink below V0.10.');

$adapter=$read('app/Domains/Growth/Infrastructure/Handoff/SalesGrowthHandoffTarget.php');
foreach(['ActiveModuleResolver',"isEnabled(\$handoff->organizationId,'sales')",'Sales module is disabled'] as $needle){
    $assert(str_contains($adapter,$needle),'Growth Sales target module-state guard missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
$assert(str_contains($services,'$modules: \'@Kernel\\Module\\ActiveModuleResolver\''),'Growth Sales adapter module resolver DI is missing.');

echo "Growth V0.10 Executable API Surface architecture: OK\n";
