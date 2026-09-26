<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.49.0','>='),'Growth manifest must remain V0.49+.');
$assert(in_array('growth.learning.conversation_binding',$manifest['contributions']['capabilities']??[],true),'V0.49 conversation learning capability missing.');
$migration='app/migrations/20260926_000114_growth_v0490_cos_for_cos_vertical_slice.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'V0.49 migration missing.');

$market=$read('app/Domains/Growth/Application/Service/GrowthMarketDiscoveryService.php');
foreach(['discoverAccount(','captureAccountSnapshot(','scoreAccount(','listSignalsBySubject(','detectCandidate('] as $needle){
    $assert(str_contains($market,$needle),'Golden path Market/Account/Signal stage missing: '.$needle);
}
$signalPos=strpos($market,'listSignalsBySubject(');
$candidatePos=strpos($market,'detectCandidate(');
$assert($signalPos!==false&&$candidatePos!==false&&$signalPos<$candidatePos,'Signal evidence must precede Candidate materialization.');

$research=$read('app/Domains/Growth/Application/Service/GrowthResearchService.php');
foreach(['whyNow','acceptProposal','markResearched('] as $needle){
    $assert(str_contains($research,$needle),'Golden path WHY NOW research stage missing: '.$needle);
}

$committee=$read('app/Domains/Growth/Application/Service/GrowthBuyingCommitteeService.php');
$assert(str_contains($committee,'assessBuyingCommittee'),'Golden path Buying Committee assessment missing.');

$engagement=$read('app/Domains/Growth/Application/Service/GrowthEngagementService.php');
$assert(str_contains($engagement,'generateRecommendation'),'Golden path Engagement recommendation missing.');

$response=$read('app/Domains/Growth/Application/Service/GrowthEngagementResponseService.php');
$classifier=$read('app/Domains/Growth/Application/Service/GrowthResponseClassificationService.php');
$assert(str_contains($response,'ENGAGEMENT_RESPONSE_RECEIVED'),'Golden path inbound Reply observation missing.');
$assert(str_contains($classifier,'ENGAGEMENT_RESPONSE_CLASSIFIED'),'Golden path Reply classification missing.');

$routing=$read('app/Domains/Growth/Application/Service/GrowthConversationRoutingService.php');
foreach(['GrowthLearningRepositoryInterface','bindExternalSubject(',"'origin'=>'conversation_routing'",'LEARNING_BINDING_CREATED'] as $needle){
    $assert(str_contains($routing,$needle),'Golden path authoritative routing/learning binding missing: '.$needle);
}
$bindPos=strpos($routing,'bindExternalSubject(');
$routedPos=strpos($routing,'ENGAGEMENT_RESPONSE_ROUTED');
$assert($bindPos!==false&&$routedPos!==false&&$bindPos<$routedPos,'External binding must be committed before routed event publication.');

$sales=$read('app/Domains/Growth/Infrastructure/Routing/SalesGrowthConversationRoutingTarget.php');
$assert(str_contains($sales,'createLead'),'Golden path Sales intake is not executable.');

$feedback=$read('app/Domains/Growth/Automation/Event/GrowthOutcomeFeedbackConsumer.php');
$bindingPos=strpos($feedback,'candidateByExternalSubject(');
$handoffFallbackPos=strpos($feedback,'candidateByTargetReference(');
$assert($bindingPos!==false&&$handoffFallbackPos!==false&&$bindingPos<$handoffFallbackPos,'Growth feedback must resolve direct conversation bindings before handoff fallback.');
foreach(['LEAD_QUALIFIED','MEETING_COMPLETED','DEAL_WON','DEAL_LOST','OUTCOME_RECORDED'] as $needle){
    $assert(str_contains($feedback,$needle),'Golden path Sales Outcome/Learning stage missing: '.$needle);
}

$docs=$read('docs/architecture/growth-v0490-cos-for-cos-vertical-slice.md');
$readme=$read('app/Domains/Growth/README.md');
$flow='Market → Account → Signal → WHY NOW → Opportunity → Committee → Outreach → Reply → Route → Sales → Outcome → Learning';
$assert(str_contains($docs,$flow)&&str_contains($readme,$flow),'Canonical V0.49 closed-loop flow documentation missing.');
$assert(str_contains($docs,'COS-for-COS'),'COS-for-COS acceptance scenario missing.');
$assert(str_contains($docs,'Signal != Opportunity'),'Golden path must preserve Signal != Opportunity.');

echo "Growth V0.49 COS-for-COS vertical slice architecture: OK\n";
