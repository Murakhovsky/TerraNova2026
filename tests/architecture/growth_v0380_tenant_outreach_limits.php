<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.38.0','>='),'Growth manifest must remain V0.38+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.38.0','>='),'Growth schema must remain V0.38+.');
$assert(in_array('growth.engagement.limit_profile',$manifest['contributions']['capabilities']??[],true),'Growth tenant limit profile capability missing.');

$migration='app/migrations/20260924_000103_growth_v0380_tenant_outreach_limits.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.38 migration missing.');
$sql=$read($migration);
foreach([
    'tn_growth_engagement_limit_profiles','uq_growth_engagement_limit_revision',
    "installed_version='0.38.0'","installed_version='0.37.0'","schema_version='0.38.0'","schema_version='0.36.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.38 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_engagement_limit_profiles'"),'Growth tenant limit profile table ownership missing.');

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementLimitService.php');
foreach([
    'GrowthEngagementLimitBoundary','GrowthEngagementLimitProviderInterface','policyFor(','engagement_limit_profile_update',
    'ENGAGEMENT_LIMIT_PROFILE_UPDATED','deployment_default','tenant_profile',
] as $needle){
    $assert(str_contains($service,$needle),'Growth tenant limit service missing: '.$needle);
}
foreach(['PDO','Infrastructure\\','AUTO','execute('] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth tenant limit application crossed boundary or enabled autonomy: '.$forbidden);
}

$execution=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach(['GrowthEngagementLimitProviderInterface','limitProvider->policyFor('] as $needle){
    $assert(str_contains($execution,$needle),'Growth execution does not consume tenant limit provider: '.$needle);
}

$controller=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
foreach(['GrowthEngagementLimitBoundary','engagementLimits(','updateEngagementLimits('] as $needle){
    $assert(str_contains($controller,$needle),'Growth limit API missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
foreach(['/api/v1/growth/engagement/limits','/growth/settings'] as $needle){
    $assert(str_contains($routes,$needle),'Growth tenant limit route missing: '.$needle);
}

$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
foreach(['GrowthEngagementLimitBoundary','function settings(','engagement_limits'] as $needle){
    $assert(str_contains($page,$needle),'Growth settings workspace wiring missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/settings.phtml');
foreach(['data-growth-settings','data-growth-limit-settings','Daily pre-handoff execution limit','Contact cooldown'] as $needle){
    $assert(str_contains($template,$needle),'Growth tenant limit workspace missing: '.$needle);
}

$js=$read('frontend/features/growth/workspace.js');
foreach(['initGrowthSettings','/api/v1/growth/engagement/limits','data-growth-limit-settings'] as $needle){
    $assert(str_contains($js,$needle),'Growth tenant limit workspace JS missing: '.$needle);
}

$provider=$read('symfony/src/Web/Experience/Extension/Provider/GrowthWebProvider.php');
$assert(str_contains($provider,"'/growth/settings'"),'Growth settings navigation missing.');

echo "Growth V0.38 Tenant Outreach Limits architecture: OK\n";
