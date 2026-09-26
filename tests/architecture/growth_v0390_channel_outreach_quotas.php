<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.39.0','>='),'Growth manifest must remain V0.39+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.39.0','>='),'Growth schema must remain V0.39+.');
$assert(in_array('growth.engagement.channel_quotas',$manifest['contributions']['capabilities']??[],true),'Growth channel quota capability missing.');

$migration='app/migrations/20260924_000104_growth_v0390_channel_outreach_quotas.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.39 migration missing.');
$sql=$read($migration);
foreach([
    'email_daily_limit','linkedin_daily_limit','phone_daily_limit',
    "installed_version='0.39.0'","installed_version='0.38.0'",
    "schema_version='0.39.0'","schema_version='0.38.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.39 migration missing: '.$needle);
}

$policy=$read('app/Domains/Growth/Domain/EngagementExecutionLimitPolicy.php');
foreach([
    'channelDailyLimits','channelDailyLimit(','pre_handoff_channel_daily_limit_reached',
    "'scope'=>'channel'","'used'=>","'limit'=>",
] as $needle){
    $assert(str_contains($policy,$needle),'Growth channel-aware limit policy missing: '.$needle);
}

$limitService=$read('app/Domains/Growth/Application/Service/GrowthEngagementLimitService.php');
foreach([
    'defaultEmailDailyLimit','defaultLinkedInDailyLimit','defaultPhoneDailyLimit',
    'channel_daily_limits','email_daily_limit','linkedin_daily_limit','phone_daily_limit',
] as $needle){
    $assert(str_contains($limitService,$needle),'Growth tenant channel quota service missing: '.$needle);
}
foreach(['PDO','Infrastructure\\','AUTO'] as $forbidden){
    $assert(!str_contains($limitService,$forbidden),'Growth limit application crossed boundary or enabled autonomy: '.$forbidden);
}

$contract=$read('app/Domains/Growth/Application/Contract/GrowthEngagementExecutionRepositoryInterface.php');
$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementExecutionRepository.php');
foreach(['countPreHandoffSinceByChannel','channel=:channel'] as $needle){
    $assert(str_contains($contract.$repo,$needle),'Growth channel usage accounting missing: '.$needle);
}

$execution=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
foreach([
    'preHandoffLimitDecision($organizationId,$contactId,$channel)',
    'countPreHandoffSinceByChannel',
    'evaluate($count,$lastAt,$now,$channel,$channelCount)',
] as $needle){
    $assert(str_contains($execution,$needle),'Growth execution does not enforce channel quota: '.$needle);
}

$profileRepo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementLimitProfileRepository.php');
foreach(['email_daily_limit','linkedin_daily_limit','phone_daily_limit'] as $needle){
    $assert(str_contains($profileRepo,$needle),'Growth channel quota persistence missing: '.$needle);
}

$template=$read('app/Interfaces/Web/View/growth/settings.phtml');
foreach(['Email daily quota','LinkedIn daily quota','Phone daily quota','quota of 0 blocks'] as $needle){
    $assert(str_contains($template,$needle),'Growth settings channel quota UI missing: '.$needle);
}
$js=$read('frontend/features/growth/workspace.js');
foreach(['email_daily_limit','linkedin_daily_limit','phone_daily_limit','channel_daily_limits'] as $needle){
    $assert(str_contains($js,$needle),'Growth settings channel quota JS missing: '.$needle);
}

$services=$read('symfony/config/services.yaml');
$compose=$read('docker-compose.yml');
foreach([
    'COS_GROWTH_OUTREACH_EMAIL_DAILY_LIMIT',
    'COS_GROWTH_OUTREACH_LINKEDIN_DAILY_LIMIT',
    'COS_GROWTH_OUTREACH_PHONE_DAILY_LIMIT',
] as $needle){
    $assert(str_contains($services,$needle)&&str_contains($compose,$needle),'Growth channel quota deployment default missing: '.$needle);
}

echo "Growth V0.39 Channel Outreach Quotas architecture: OK\n";
