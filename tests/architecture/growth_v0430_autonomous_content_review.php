<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.43.0','>='),'Growth manifest must remain V0.43+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.43.0','>='),'Growth schema must remain V0.43+.');
foreach(['growth.engagement.autonomous_content_drafting','growth.engagement.autonomous_content_review','growth.engagement.autonomous_content_scheduler'] as $cap){
    $assert(in_array($cap,$manifest['contributions']['capabilities']??[],true),'Missing V0.43 capability: '.$cap);
}

$migration='app/migrations/20260926_000108_growth_v0430_autonomous_content_review.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'V0.43 migration missing.');
$sql=$read($migration);
foreach(['tn_growth_engagement_content_review_profiles','tn_growth_engagement_content_runs','tn_growth_engagement_content_drafts','active_key','uq_growth_content_active_run',"installed_version='0.43.0'","installed_version='0.42.0'"] as $needle){
    $assert(str_contains($sql,$needle),'V0.43 migration missing: '.$needle);
}

$prompt=$read('app/Domains/Growth/Application/AI/GrowthAutonomousContentPrompt.php');
foreach(['Never invent','internal candidate IDs','delivery_identity_leak','risk_flags','evidence_ids','growth-autonomous-content-v1'] as $needle){
    $assert(str_contains($prompt,$needle),'Content drafting prompt missing safety contract: '.$needle);
}
$gateway=$read('app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthAutonomousContentGateway.php');
foreach(['StructuredLlmClientInterface',"useCase:'growth.engagement.content_draft'",'GrowthAutonomousContentPrompt::schema()'] as $needle){
    $assert(str_contains($gateway,$needle),'Content gateway missing governed LLM marker: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthAutonomousContentService.php');
$setup=strpos($service,'$setup=$this->transactions->transactional');
$llm=strpos($service,'$this->gateway->draft(');
$completion=strpos($service,'$this->executions->lockPreHandoffCapacity($organizationId)',$llm);
$review=strpos($service,'$policy->evaluate(',$completion);
$stage=strpos($service,'$this->autonomy->stagePayload(',$review);
$assert($setup!==false&&$llm!==false&&$llm>$setup,'Content LLM call must happen after setup transaction.');
$assert($completion!==false&&$completion>$llm,'Content completion must reacquire tenant lock after LLM call.');
$assert($review!==false&&$stage!==false&&$stage>$review,'Content auto-stage must happen only after current review policy evaluation.');
foreach(['internal_reference_leak','delivery_identity_leak',"'llm_context'",'internal_reference_ids','Growth content already has a draft pending human review.','Human-approved generated Growth content','content-auto-stage-','content-human-stage-'] as $needle){
    $assert(str_contains($service,$needle),'Content service missing governance marker: '.$needle);
}
$assert(!str_contains($service,"'expected_value'=>"),'Content LLM context must not expose internal expected value.');
$assert(!str_contains($service,"'confidence'=>\$recommendation"),'Content LLM context must not expose recommendation confidence.');
$assert(!str_contains($service,'latestIcpMatch($organizationId,$subjectId)'),'Content LLM context must not expose internal ICP scoring.');



$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthAutonomousContentRepository.php');
foreach(['autonomy.enabled=1',"END='auto'","'human_review'","source_domain=\\'sales\\'","tn_growth_engagement_autonomy_payloads"] as $needle){
    $assert(str_contains($repo,$needle),'Content scheduler candidate query missing: '.$needle);
}
$assert(str_contains($repo,'active_key=NULL'),'Completed or failed content runs must release the active generation guard.');
$assert(str_contains($repo,'Growth content generation is already active for this recommendation.'),'Concurrent content generation must be rejected before a second LLM call.');
$assert(str_contains($repo,'Generation lease expired before completion.'),'Crashed content generation must release its active lease.');
$assert(str_contains($repo,'INTERVAL 15 MINUTE'),'Content generation lease recovery window is missing.');

$outreach=$read('app/Domains/Growth/Application/Service/GrowthAutonomousOutreachService.php');
$boundary=$read('app/Domains/Growth/Application/Contract/GrowthAutonomousOutreachBoundary.php');
$assert(str_contains($boundary,'string $actorType=\'USER\''),'V0.42 staging boundary must preserve actor provenance.');
$assert(str_contains($outreach,'$correlationId,$actorType,(string)$actorId'),'Autonomous payload staging events must use the supplied actor type.');



$scheduler=$read('symfony/src/Scheduler/CosScheduleProvider.php');
$messenger=$read('symfony/config/packages/messenger.yaml');
$services=$read('symfony/config/services.yaml');
foreach(['RunGrowthAutonomousContentCommand','growthAutonomousContentEnabled','growthAutonomousContentIntervalMinutes'] as $needle){
    $assert(str_contains($scheduler,$needle),'Content scheduler wiring missing: '.$needle);
}
$assert(str_contains($messenger,"'App\\Application\\Growth\\Command\\RunGrowthAutonomousContentCommand': async"),'Content command must use async Messenger transport.');
$assert(str_contains($services,"env(COS_GROWTH_CONTENT_SCHEDULER_ENABLED): '0'"),'Content scheduler must default OFF.');
foreach(['COS_GROWTH_CONTENT_SCHEDULER_ACTOR_ID','COS_GROWTH_CONTENT_SCHEDULER_DRAFT_LIMIT','RunGrowthAutonomousContentCommandHandler'] as $needle){
    $assert(str_contains($services,$needle),'Content scheduler DI missing: '.$needle);
}

$handler=$read('symfony/src/Application/Growth/Command/RunGrowthAutonomousContentCommandHandler.php');
foreach(['enabledOrganizations(','draftCandidates(','scheduled-content:','generateDraft(','SYSTEM'] as $needle){
    $assert(str_contains($handler,$needle),'Content scheduler handler missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$settings=$read('app/Interfaces/Web/View/growth/settings.phtml');
$candidate=$read('app/Interfaces/Web/View/growth/candidate.phtml');
$js=$read('frontend/features/growth/workspace.js');
foreach(['/api/v1/growth/engagement/content-review','/content/drafts','approveEngagementContentDraft','rejectEngagementContentDraft'] as $needle){
    $assert(str_contains($routes.$api,$needle),'Content API surface missing: '.$needle);
}
$assert(str_contains($page,'engagement_content_review')&&str_contains($page,'engagement_content'),'Growth pages must expose content review/read model.');
foreach(['data-growth-content-review-settings','Policy auto-approve','Minimum draft confidence'] as $needle){
    $assert(str_contains($settings,$needle),'Content settings UI missing: '.$needle);
}
foreach(['data-growth-content-generate','data-growth-content-decision','Approve & stage','Generate new governed draft'] as $needle){
    $assert(str_contains($candidate,$needle),'Candidate content review UI missing: '.$needle);
}
foreach(["'/api/v1/growth/engagement/content-review'","'/content/drafts'"] as $needle){
    $assert(str_contains($js,$needle),'Content frontend mutation missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(["'tn_growth_engagement_content_review_profiles'","'tn_growth_engagement_content_runs'","'tn_growth_engagement_content_drafts'"] as $needle){
    $assert(str_contains($ownership,$needle),'Content table ownership missing: '.$needle);
}

$readme=$read('app/Domains/Growth/README.md');
$assert(str_contains($readme,'Generated content is a convenience layer, not a new source of execution authority.'),'Content authority boundary must be documented.');

echo "Growth V0.43 Autonomous Content Review architecture: OK\n";
