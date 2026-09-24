<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.37.0','>='),'Growth manifest must remain V0.37+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.36.0','>='),'Growth schema must remain V0.36+.');
$assert(in_array('growth.engagement.execution_limits',$manifest['contributions']['capabilities']??[],true),'Growth execution limits capability missing.');

$migration='app/migrations/20260924_000102_growth_v0370_outreach_guardrails.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.37 lifecycle migration missing.');
$sql=$read($migration);
foreach(["installed_version='0.37.0'","installed_version='0.36.0'","schema_version='0.36.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.37 migration missing: '.$needle);
}
foreach(['CREATE TABLE','ALTER TABLE','DROP TABLE'] as $forbidden){
    $assert(!str_contains(strtoupper($sql),$forbidden),'Growth V0.37 guardrails migration must remain schema-neutral.');
}

$policy=$read('app/Domains/Growth/Domain/EngagementExecutionLimitPolicy.php');
foreach(['dailyLimit','contactCooldownHours','pre_handoff_daily_limit_reached','pre_handoff_contact_cooldown','next_allowed_at'] as $needle){
    $assert(str_contains($policy,$needle),'Growth execution limit policy missing: '.$needle);
}

$contract=$read('app/Domains/Growth/Application/Contract/GrowthEngagementExecutionRepositoryInterface.php');
foreach(['countPreHandoffSince(','latestPreHandoffForTarget('] as $needle){
    $assert(str_contains($contract,$needle),'Growth execution limit repository contract missing: '.$needle);
}

$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementExecutionRepository.php');
foreach([
    'countPreHandoffSince(','latestPreHandoffForTarget(',
    "target_domain='growth'","created_at>=:since",
] as $needle){
    $assert(str_contains($repo,$needle),'Growth execution limit repository implementation missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach([
    'GrowthEngagementLimitProviderInterface','preHandoffLimitDecision(',
    "'pre_handoff_limits'","pre_handoff_daily_limit_reached","pre_handoff_contact_cooldown",
] as $needle){
    $assert(str_contains($service,$needle),'Growth execution guardrails missing: '.$needle);
}
foreach(['AUTO','autonomous','execute('] as $forbidden){
    $assert(!str_contains($service,$forbidden),'Growth V0.37 must not introduce autonomous outreach: '.$forbidden);
}

$services=$read('symfony/config/services.yaml');
foreach([
    'COS_GROWTH_OUTREACH_DAILY_LIMIT','COS_GROWTH_OUTREACH_CONTACT_COOLDOWN_HOURS',
    'GrowthEngagementLimitService',
] as $needle){
    $assert(str_contains($services,$needle),'Growth execution guardrail config missing: '.$needle);
}

echo "Growth V0.37 Pre-Handoff Outreach Guardrails architecture: OK\n";
