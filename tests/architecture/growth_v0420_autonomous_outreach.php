<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.42.0','>='),'Growth manifest must remain V0.42+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.42.0','>='),'Growth schema must remain V0.42+.');
foreach(['growth.engagement.autonomy_policy','growth.engagement.autonomy_payload_staging','growth.engagement.autonomous_trigger'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Missing autonomy capability: '.$capability);
}

$migration='app/migrations/20260925_000107_growth_v0420_autonomous_outreach.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'V0.42 migration missing.');
$sql=$read($migration);
foreach(['tn_growth_engagement_autonomy_profiles','tn_growth_engagement_autonomy_payloads','allowed_channels_json','allowed_statuses_json',"installed_version='0.42.0'","installed_version='0.41.0'"] as $needle){
    $assert(str_contains($sql,$needle),'V0.42 migration missing: '.$needle);
}

$policy=$read('app/Domains/Growth/Domain/AutonomousOutreachEligibilityPolicy.php');
foreach(['autonomy_disabled','staged_payload_required','confidence_below_autonomy_threshold','channel_not_allowed_for_autonomy','channel_not_auto','eligible_with_auto_accept'] as $needle){
    $assert(str_contains($policy,$needle),'Autonomous eligibility policy missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthAutonomousOutreachService.php');
$triggerPos=strpos($service,'public function triggerRecommendation(');
$lock=strpos($service,'lockPreHandoffCapacity($organizationId)',$triggerPos);
$decision=strpos($service,'$policy->evaluate($recommendation,$payload!==null,$activation)',$lock);
$execution=strpos($service,'$this->execution->proposeMessageAction(',$decision);
$assert($triggerPos!==false&&$lock!==false&&$decision!==false&&$decision>$lock,'Autonomy must re-evaluate policy under tenant lock.');
$assert($execution!==false&&$execution>$decision,'Autonomous execution must happen after authoritative eligibility admission.');
foreach(['autonomy-accept-','autonomy-execution-','autonomy_pre_handoff_only','AutonomousOutreachDeferred','ENGAGEMENT_AUTONOMOUS_TRIGGERED'] as $needle){
    $assert(str_contains($service,$needle),'Autonomous runtime missing: '.$needle);
}
$assert(!str_contains($service,'GrowthEngagementPrompt'),'Autonomous trigger must not author LLM outreach copy.');

$scheduler=$read('symfony/src/Scheduler/CosScheduleProvider.php');
$messenger=$read('symfony/config/packages/messenger.yaml');
$services=$read('symfony/config/services.yaml');
foreach(['RunGrowthAutonomousOutreachCommand','growthAutonomousOutreachEnabled','growthAutonomousOutreachIntervalMinutes'] as $needle){
    $assert(str_contains($scheduler,$needle),'Autonomy scheduler missing: '.$needle);
}
$assert(str_contains($messenger,"'App\\Application\\Growth\\Command\\RunGrowthAutonomousOutreachCommand': async"),'Autonomy command must use async transport.');
foreach(['COS_GROWTH_AUTONOMY_SCHEDULER_ENABLED','COS_GROWTH_AUTONOMY_SCHEDULER_ACTOR_ID','COS_GROWTH_AUTONOMY_SCHEDULER_RECOMMENDATION_LIMIT'] as $needle){
    $assert(str_contains($services,$needle),'Autonomy scheduler configuration missing: '.$needle);
}
$assert(str_contains($services,"env(COS_GROWTH_AUTONOMY_SCHEDULER_ENABLED): '0'"),'Deployment autonomy scheduler must default OFF.');

$handler=$read('symfony/src/Application/Growth/Command/RunGrowthAutonomousOutreachCommandHandler.php');
foreach(['enabledOrganizations(','pendingPayloads(','isEnabled($organizationId,\'growth\')','triggerRecommendation(','tenantTriggered'] as $needle){
    $assert(str_contains($handler,$needle),'Autonomy handler missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$template=$read('app/Interfaces/Web/View/growth/settings.phtml');
$candidate=$read('app/Interfaces/Web/View/growth/candidate.phtml');
$js=$read('frontend/features/growth/workspace.js');
foreach(['/api/v1/growth/engagement/autonomy','/autonomy/payload','stageEngagementAutonomyPayload'] as $needle){
    $assert(str_contains($routes.$api,$needle),'Autonomy API surface missing: '.$needle);
}
$assert(str_contains($page,'engagement_autonomy'),'Growth pages must consume autonomy read model.');
foreach(['data-growth-autonomy-settings','Safe default is OFF','Allowed recommendation states'] as $needle){
    $assert(str_contains($template,$needle),'Autonomy settings UI missing: '.$needle);
}
foreach(['data-growth-engagement-autonomy-payload','Staging does not send anything'] as $needle){
    $assert(str_contains($candidate,$needle),'Candidate payload staging UI missing: '.$needle);
}
foreach(["'/api/v1/growth/engagement/autonomy'","'/autonomy/payload'"] as $needle){
    $assert(str_contains($js,$needle),'Autonomy frontend mutation missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(["'tn_growth_engagement_autonomy_profiles'","'tn_growth_engagement_autonomy_payloads'"] as $needle){
    $assert(str_contains($ownership,$needle),'Autonomy table ownership missing: '.$needle);
}

$readme=$read('app/Domains/Growth/README.md');
$assert(str_contains($readme,'double opt-in'),'Autonomy double opt-in boundary must be documented.');
$assert(str_contains($readme,'does **not** let an LLM author final outreach copy at trigger time'),'Autonomy content boundary must be documented.');

echo "Growth V0.42 Autonomous Outreach architecture: OK\n";
