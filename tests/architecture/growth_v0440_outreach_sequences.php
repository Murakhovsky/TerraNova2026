<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.44.0','>='),'Growth manifest must remain V0.44+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.44.0','>='),'Growth schema must remain V0.44+.');
foreach(['growth.engagement.sequence_policy','growth.engagement.sequence_state_machine','growth.engagement.sequence_scheduler'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Missing V0.44 capability: '.$capability);
}

$migration='app/migrations/20260926_000109_growth_v0440_outreach_sequences.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'V0.44 migration contribution missing.');
$sql=$read($migration);
foreach([
    'tn_growth_engagement_sequence_profiles','tn_growth_engagement_sequences','tn_growth_engagement_sequence_steps',
    'activation_started_at','uq_growth_sequence_root',
    "installed_version='0.44.0'","installed_version='0.43.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'V0.44 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach([
    "'tn_growth_engagement_sequence_profiles'","'tn_growth_engagement_sequences'","'tn_growth_engagement_sequence_steps'",
] as $needle){
    $assert(str_contains($ownership,$needle),'V0.44 table ownership missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthOutreachSequenceService.php');
foreach([
    'growth-sequence-v1','deterministic-follow-up','EngagementRecommendationStatus::Accepted','reply_status_unknown',
    'ENGAGEMENT_RUN_STARTED','ENGAGEMENT_RUN_COMPLETED','ENGAGEMENT_SEQUENCE_ADVANCED',
    "'drained_disabled'",'growth.engagement.sequence_advanced',
] as $needle){
    $assert(str_contains($service,$needle),'Sequence runtime missing: '.$needle);
}
$assert(!str_contains($service,'StructuredLlmClientInterface'),'Sequence state machine must not author copy through an LLM.');

$guard=$read('app/Domains/Growth/Application/Service/GrowthOutreachSequenceGuard.php');
foreach([
    'sales_handoff','outcome_','phone_completed','delivery_failed','sequence_policy_disabled',
    'sequence_content_blocked','sequence_channel_not_auto','autonomy_disabled','autonomy_channel_not_allowed',
    'autonomy_accepted_status_not_allowed','autonomy_confidence_below_threshold',
] as $needle){
    $assert(str_contains($guard,$needle),'Central sequence guard missing: '.$needle);
}

$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthOutreachSequenceRepository.php');
foreach([
    'e.created_at>=policy.activation_started_at','policy.enabled=1','sq.sequence_id IS NULL',
    'schedulerOrganizations',"WHERE status=\'active\'",'sequenceForRecommendation',
] as $needle){
    $assert(str_contains($repo,$needle),'Sequence repository missing: '.$needle);
}

$outreach=$read('app/Domains/Growth/Application/Service/GrowthAutonomousOutreachService.php');
$outreachRepo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthAutonomousOutreachRepository.php');
$content=$read('app/Domains/Growth/Application/Service/GrowthAutonomousContentService.php');
$contentRepo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthAutonomousContentRepository.php');

$assert(
    str_contains($outreach,'GrowthOutreachSequenceGuardInterface')&&str_contains($outreach,'blockingForRecommendation'),
    'V0.42 trigger must use the central sequence guard.'
);
$assert(str_contains($outreachRepo,"sq.status<>'active'"),'V0.42 pending payload discovery must exclude inactive sequence recommendations.');
$assert(
    str_contains($content,'GrowthOutreachSequenceGuardInterface')
    &&str_contains($content,'assertActiveSequenceRecommendation')
    &&str_contains($content,'blockingForRecommendation'),
    'V0.43 content runtime must use the central sequence guard.'
);
$llmPos=strpos($content,'$this->gateway->draft(');
$postLlmLock=strpos($content,'$this->executions->lockPreHandoffCapacity($organizationId);',$llmPos===false?0:$llmPos);
$postLlmGuard=strpos($content,'$this->assertActiveSequenceRecommendation($organizationId,$recommendationId);',$postLlmLock===false?0:$postLlmLock);
$assert($llmPos!==false&&$postLlmLock!==false&&$postLlmGuard!==false&&$postLlmGuard>$postLlmLock,'V0.43 must recheck sequence guard after LLM under tenant lock.');
$assert(str_contains($content,'if($approve)$this->assertActiveSequenceRecommendation'),'Rejecting a stale sequence draft must remain possible.');
$assert(str_contains($contentRepo,"sq.status<>\'active\'"),'V0.43 content scheduler must exclude inactive sequence recommendations.');

$machine=$read('app/Domains/Growth/Domain/OutreachSequenceStateMachine.php');
foreach(['delivery_observation_pending','phone_outcome_pending','max_touches_reached','follow_up_due'] as $needle){
    $assert(str_contains($machine,$needle),'Sequence state machine missing: '.$needle);
}

$scheduler=$read('symfony/src/Scheduler/CosScheduleProvider.php');
$handler=$read('symfony/src/Application/Growth/Command/RunGrowthOutreachSequencesCommandHandler.php');
$services=$read('symfony/config/services.yaml');
$messenger=$read('symfony/config/packages/messenger.yaml');
$docker=$read('docker-compose.yml');
$assert(str_contains($scheduler,'RunGrowthOutreachSequencesCommand'),'Sequence scheduler missing.');
$assert(str_contains($handler,'schedulerOrganizations'),'Sequence scheduler must drain active rows after policy disable.');
$assert(str_contains($services,"env(COS_GROWTH_SEQUENCE_SCHEDULER_ENABLED): '0'"),'Sequence scheduler must default OFF.');
$assert(str_contains($services,'GrowthOutreachSequenceGuardInterface'),'Central sequence guard DI missing.');
$assert(str_contains($messenger,'RunGrowthOutreachSequencesCommand'),'Sequence Messenger route missing.');
$assert(str_contains($docker,'COS_GROWTH_SEQUENCE_SCHEDULER_ENABLED'),'Sequence deployment configuration missing.');

$routes=$read('symfony/config/routes.yaml');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$settings=$read('app/Interfaces/Web/View/growth/settings.phtml');
$candidate=$read('app/Interfaces/Web/View/growth/candidate.phtml');
$js=$read('frontend/features/growth/workspace.js');

foreach([
    '/api/v1/growth/engagement/sequences/policy',
    '/engagement/sequence/{sequenceId}/stop',
    'updateEngagementSequencePolicy',
    'stopEngagementSequence',
] as $needle){
    $assert(str_contains($routes.$api,$needle),'Sequence API surface missing: '.$needle);
}
$assert(str_contains($page,'engagement_sequence_policy')&&str_contains($page,'engagement_sequence'),'Growth SSR pages must expose sequence state.');
foreach(['data-growth-sequence-settings','Maximum touches including the first','Follow-up delay'] as $needle){
    $assert(str_contains($settings,$needle),'Sequence settings UI missing: '.$needle);
}
foreach(['data-growth-sequence-stop','Outreach sequence','Stop sequence','sequencePolicyEffective'] as $needle){
    $assert(str_contains($candidate,$needle),'Sequence Candidate UI missing: '.$needle);
}
foreach(["'/api/v1/growth/engagement/sequences/policy'","'/sequence/'"] as $needle){
    $assert(str_contains($js,$needle),'Sequence frontend mutation missing: '.$needle);
}

$readme=$read('app/Domains/Growth/README.md');
foreach([
    'Stopping a sequence is an execution boundary, not a cosmetic status.',
    'central `GrowthOutreachSequenceGuard`',
    'delivery callbacks',
    'reconciled to `stopped`',
] as $needle){
    $assert(str_contains($readme,$needle),'Sequence documentation missing: '.$needle);
}

echo "Growth V0.44 Outreach Sequences architecture: OK\n";
