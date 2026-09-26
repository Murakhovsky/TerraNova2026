<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.41.0','>='),'Growth manifest must remain V0.41+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.41.0','>='),'Growth schema must remain V0.41+.');
$assert(in_array('growth.engagement.activation_policy',$manifest['contributions']['capabilities']??[],true),'Growth activation capability missing.');

$migration='app/migrations/20260924_000106_growth_v0410_outreach_activation_policy.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'Growth V0.41 migration missing.');
$sql=$read($migration);
foreach(['tn_growth_engagement_activation_profiles','email_mode','linkedin_mode','phone_mode',"installed_version='0.41.0'","installed_version='0.40.0'","schema_version='0.41.0'","schema_version='0.40.0'"] as $needle){
    $assert(str_contains($sql,$needle),'Growth V0.41 migration missing: '.$needle);
}

$catalog=$read('app/Domains/Growth/Automation/Policy/GrowthPolicyCatalog.php');
foreach(["'growth.activation_mode'","PolicyDecision::Denied","PolicyDecision::Auto","PolicyDecision::ApprovalRequired","'growth-send-message-approval-v1'","'growth-linkedin-approval-v1'","'growth-call-approval-v1'"] as $needle){
    $assert(str_contains($catalog,$needle),'Growth activation Kernel policy missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementActivationService.php');
foreach(['lockPreHandoffCapacity($organizationId)','engagement_activation_profile_update','ENGAGEMENT_ACTIVATION_PROFILE_UPDATED',"'approval_required'"] as $needle){
    $assert(str_contains($service,$needle),'Growth activation service missing: '.$needle);
}
$assert(!str_contains($service,'PDO'),'Growth activation application service must not own persistence.');

$execution=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
$lockPos=strpos($execution,'$this->executions->lockPreHandoffCapacity($organizationId);');
$activationPos=strpos($execution,'$this->activationProvider->modeFor($organizationId,$channel);',$lockPos);
$limitPos=strpos($execution,'$this->preHandoffLimitDecision($organizationId,$targetReferenceId,$channel);',$activationPos);
$assert($lockPos!==false&&$activationPos!==false&&$activationPos>$lockPos,'Authoritative activation check must happen after tenant lock.');
$assert($limitPos!==false&&$limitPos>$activationPos,'Quota admission must remain after activation authorization.');

$gateway=$read('app/Domains/Growth/Infrastructure/Action/KernelGrowthActionProposalGateway.php');
foreach(['GrowthEngagementActivationProviderInterface','executionMode:$activationMode->executionMode()',"'activation_mode'=>$activationMode->value"] as $needle){
    $assert(str_contains($gateway,$needle),'Growth Action policy context missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$template=$read('app/Interfaces/Web/View/growth/settings.phtml');
$js=$read('frontend/features/growth/workspace.js');
foreach(['/api/v1/growth/engagement/activation','engagementActivation','updateEngagementActivation'] as $needle){
    $assert(str_contains($routes.$api,$needle),'Growth activation API surface missing: '.$needle);
}
$assert(str_contains($page,'engagement_activation'),'Growth Settings must read activation profile.');
foreach(['data-growth-activation-settings',"'auto'=>'Auto'",'approval_required'] as $needle){
    $assert(str_contains($template,$needle),'Growth activation settings UI missing: '.$needle);
}
foreach(["'/api/v1/growth/engagement/activation'","channel_modes"] as $needle){
    $assert(str_contains($js,$needle),'Growth activation settings JS missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
$assert(str_contains($ownership,"'tn_growth_engagement_activation_profiles'"),'Growth activation profile table ownership missing.');
$readme=$read('app/Domains/Growth/README.md');
$assert(str_contains($readme,'V0.41 adds tenant-owned outreach authorization'),'Growth V0.41 README missing.');
$assert(str_contains($readme,'does not mean background autonomous prospecting'),'V0.41 autonomy boundary is undocumented.');

echo "Growth V0.41 Outreach Activation Policy architecture: OK\n";
