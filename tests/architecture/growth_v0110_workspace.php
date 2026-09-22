<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.11.0','>='),'Growth manifest must remain V0.11+.');
$assert(($manifest['schema_version']??null)==='0.8.0','Growth V0.11 must keep schema version 0.8.0.');
$assert(($manifest['enabled_by_default']??true)===false,'Growth V0.11 must remain disabled before tenant cutover.');
$assert(in_array('growth.workspace',$manifest['contributions']['capabilities']??[],true),'Growth Workspace capability is missing.');
$lifecycleMigration='app/migrations/20260922_000075_growth_v0110_workspace.sql';
$assert(in_array($lifecycleMigration,$manifest['contributions']['migration_files']??[],true),'Growth V0.11 lifecycle migration is missing.');
$lifecycleSql=$read($lifecycleMigration);
foreach(["installed_version='0.11.0'","schema_version='0.8.0'","installed_version='0.10.0'"] as $needle){
    $assert(str_contains($lifecycleSql,$needle),'Growth V0.11 lifecycle migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($lifecycleSql),$forbidden),'Growth V0.11 lifecycle migration must not change schema: '.$forbidden);
}

foreach(['web.navigation','web.search','web.commands','web.workspace'] as $point){
    $values=$manifest['contributions']['extension_services'][$point]??[];
    $assert($values===['growthNavigationContributor'],'Growth manifest extension contribution mismatch: '.$point);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php');
foreach([
    'NavigationProviderInterface','SearchProviderInterface','CommandProviderInterface','WorkspaceProviderInterface',
    "return 'growthNavigationContributor';","'/growth'","'/growth/candidates'","'/growth/accounts'",
    "'growth.overview'","'growth.candidate'","'growth.account'",
] as $needle){
    $assert(str_contains($provider,$needle),'Growth Web provider missing: '.$needle);
}
foreach(['PDO','RepositoryInterface','HttpClientInterface','fetch(','/api/v1/'] as $forbidden){
    $assert(!str_contains($provider,$forbidden),'Growth Web provider crossed provider boundary: '.$forbidden);
}

$controller=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach([
    'ProviderBackedShellNavigation','WebExtensionContext','GrowthWorkspaceReadModelInterface',
    'GrowthApplicationBoundary','GrowthIntelligenceBoundary','GrowthBuyingCommitteeBoundary',
    'GrowthResearchBoundary','GrowthDecisionBoundary','GrowthHandoffBoundary',
    "isEnabled(\$tenant->organizationId()->value(),'growth')",'growth-workspace',
] as $needle){
    $assert(str_contains($controller,$needle),'Growth Workspace controller missing: '.$needle);
}
foreach(['PDO','NavigationBuilder','Infrastructure\\Persistence','MysqlGrowth'] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Growth Workspace controller crossed boundary: '.$forbidden);
}

$readModel=$read('app/Domains/Growth/Infrastructure/ReadModel/MySql/MysqlGrowthWorkspaceReadModel.php');
foreach([
    'GrowthWorkspaceReadModelInterface','tn_growth_candidates','tn_growth_accounts',
    'tn_growth_candidate_evaluations','tn_growth_handoff_attempts','organization_id=:organization_id',
] as $needle){
    $assert(str_contains($readModel,$needle),'Growth Workspace read model missing: '.$needle);
}
$assert(!str_contains($readModel,'INSERT INTO'),'Growth Workspace read model must remain read-only.');
$assert(!str_contains($readModel,'UPDATE tn_growth'),'Growth Workspace read model must remain read-only.');

$routes=$read('symfony/config/routes.yaml');
foreach([
    'path: /growth',
    'path: /growth/candidates',
    'path: /growth/candidates/{id}',
    'path: /growth/accounts',
    'path: /growth/accounts/{id}',
] as $route){
    $assert(str_contains($routes,$route),'Growth Workspace route missing: '.$route);
}
$assert(substr_count($routes,'App\\Web\\Growth\\GrowthPageController::')>=5,'Growth V0.11 core SSR routes must remain available.');

$vite=$read('vite.config.js');
$assert(str_contains($vite,"'growth-workspace': resolve(import.meta.dirname, 'frontend/entrypoints/growth-workspace.js')"),'Growth Vite entry is missing.');

$services=$read('symfony/config/services.yaml');
foreach([
    'growthNavigationContributor','GrowthWebProvider','GrowthWorkspaceReadModelInterface',
    'MysqlGrowthWorkspaceReadModel','App\\Web\\Growth\\GrowthPageController',
] as $needle){
    $assert(str_contains($services,$needle),'Growth Workspace DI missing: '.$needle);
}

echo "Growth V0.11 Workspace architecture: OK\n";
