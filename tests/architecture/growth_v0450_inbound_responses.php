<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$manifest=require $root.'/app/Domains/Growth/module.php';
$assert(version_compare((string)($manifest['version']??'0.0.0'),'0.45.0','>='),'Growth manifest must remain V0.45+.');
$assert(version_compare((string)($manifest['schema_version']??'0.0.0'),'0.45.0','>='),'Growth schema must remain V0.45+.');
foreach(['growth.engagement.response_webhook','growth.engagement.inbound_response','growth.engagement.response_classification'] as $capability){
    $assert(in_array($capability,$manifest['contributions']['capabilities']??[],true),'Missing V0.45 capability: '.$capability);
}

$migration='app/migrations/20260926_000110_growth_v0450_inbound_responses.sql';
$assert(in_array($migration,$manifest['contributions']['migration_files']??[],true),'V0.45 migration missing.');
$sql=$read($migration);
foreach([
    'tn_growth_engagement_responses','tn_growth_engagement_response_classifications',
    'body MEDIUMTEXT','body_hash','uq_growth_engagement_response_source',
    'uq_growth_response_classification_version',"installed_version='0.45.0'","installed_version='0.44.0'",
] as $needle){
    $assert(str_contains($sql,$needle),'V0.45 migration missing: '.$needle);
}

$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(["'tn_growth_engagement_responses'","'tn_growth_engagement_response_classifications'"] as $needle){
    $assert(str_contains($ownership,$needle),'V0.45 table ownership missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthEngagementResponseService.php');
foreach([
    'lockPreHandoffCapacity(','byActionId(','GrowthOutcomeType::ReplyReceived',
    'ENGAGEMENT_RESPONSE_RECEIVED','OUTCOME_RECORDED',"'growth.send_message'","'growth.send_linkedin'","'growth.place_call'",
] as $needle){
    $assert(str_contains($service,$needle),'Inbound response service missing: '.$needle);
}
$lock=strpos($service,'lockPreHandoffCapacity(');
$outcome=strpos($service,'recordOutcome(');
$assert($lock!==false&&$outcome!==false&&$lock<$outcome,'reply_received must be recorded under tenant admission lock.');
$assert(str_contains($service,"setTimezone(new DateTimeZone('UTC'))"),'Inbound response timestamp must normalize to UTC.');

$sequenceGuard=$read('app/Domains/Growth/Application/Service/GrowthOutreachSequenceGuard.php');
$executionService=$read('app/Domains/Growth/Application/Service/GrowthEngagementExecutionService.php');
$assert(str_contains($sequenceGuard,"'reply_received'"),'Sequence hard-stop vocabulary must include reply_received.');
$assert(str_contains($sequenceGuard,'hardBlockForRecommendation'),'Sequence guard must expose a hard execution block.');
$assert(str_contains($executionService,'GrowthOutreachSequenceGuardInterface'),'Manual governed execution must depend on the sequence hard guard.');
$assert(substr_count($executionService,'hardBlockForRecommendation(')>=3,'Execution proposal, locked admission and eligibility must all enforce sequence hard stop.');
$proposalPos=strpos($executionService,'public function proposeMessageAction');
$existingPos=strpos($executionService,'$existing=$this->executions->byRecommendation',$proposalPos);
$fastHardPos=strpos($executionService,'$hardBlock=$this->sequenceGuard->hardBlockForRecommendation',$proposalPos);
$receiptPos=strpos($executionService,"'engagement_execution_payload'",$proposalPos);
$assert($existingPos!==false&&$fastHardPos!==false&&$existingPos<$fastHardPos,'Existing execution replay must precede the fast sequence hard block.');
$assert($receiptPos!==false&&$fastHardPos<$receiptPos,'Blocked new execution must not claim a payload receipt before the hard stop.');
$assert(str_contains($sequenceGuard,"new DateTimeZone('UTC')"),'Sequence stop comparisons must interpret persisted timestamps as UTC.');

$repo=$read('app/Domains/Growth/Infrastructure/Persistence/MySql/MysqlGrowthEngagementResponseRepository.php');
foreach(["format('Y-m-d H:i:s.u')",'body_hash','provider_reference','thread_reference'] as $needle){
    $assert(str_contains($repo,$needle),'Response persistence contract missing: '.$needle);
}

$prompt=$read('app/Domains/Growth/Application/AI/GrowthResponseClassificationPrompt.php');
foreach(['meeting_request','unsubscribe','recommended_next_owner is advisory only','human_review'] as $needle){
    $assert(str_contains($prompt,$needle),'Response classification prompt missing: '.$needle);
}

$gateway=$read('app/Domains/Growth/Infrastructure/AI/StructuredLlmGrowthResponseClassificationGateway.php');
foreach(['StructuredLlmClientInterface','growth.engagement.response_classify','GrowthResponseClassificationPrompt::schema()'] as $needle){
    $assert(str_contains($gateway,$needle),'Response classification gateway missing: '.$needle);
}

$classifier=$read('app/Domains/Growth/Application/Service/GrowthResponseClassificationService.php');
foreach([
    'classificationForVersion(','lockById(','appendClassification(','ENGAGEMENT_RESPONSE_CLASSIFIED',
    "'response'=>[","'prior_outreach'=>","'candidate_context'=>",
] as $needle){
    $assert(str_contains($classifier,$needle),'Response classification service missing: '.$needle);
}
foreach(['GrowthHandoffBoundary','SalesWriteService','ServiceApplicationBoundary'] as $forbidden){
    $assert(!str_contains($classifier,$forbidden),'V0.45 classifier must remain advisory: '.$forbidden);
}

$consumer=$read('app/Domains/Growth/Automation/Event/GrowthResponseClassificationConsumer.php');
foreach(['implements DurableEventConsumerInterface',"growth.response-classification.v1",'ENGAGEMENT_RESPONSE_RECEIVED','classifyResponse('] as $needle){
    $assert(str_contains($consumer,$needle),'Durable response classifier missing: '.$needle);
}

$webhook=$read('symfony/src/Application/Growth/Integration/GrowthEngagementResponseWebhook.php');
foreach([
    'GrowthEngagementResponseBoundary','X-TN-Idempotency-Key','hash_hmac',
    'kernel_action_id','organization_id','recordExternalResponse(',
] as $needle){
    $assert(str_contains($webhook,$needle),'Response webhook missing: '.$needle);
}

$publicEdge=$read('symfony/src/Web/PublicEdge/PublicEdgeController.php');
$routes=$read('symfony/config/routes.yaml');
$services=$read('symfony/config/services.yaml');
$docker=$read('docker-compose.yml');
foreach(['GrowthEngagementResponseWebhook','growthEngagementResponseWebhook'] as $needle){
    $assert(str_contains($publicEdge,$needle),'Public response edge missing: '.$needle);
}
$assert(str_contains($routes,'/webhooks/growth/engagement/responses'),'Growth response webhook route missing.');
$assert(str_contains($routes,'/api/v1/growth/candidates/{id}/engagement/responses'),'Growth response read API missing.');
foreach([
    'GROWTH_ENGAGEMENT_RESPONSE_WEBHOOK_SECRET','MysqlGrowthEngagementResponseRepository',
    'GrowthResponseClassificationGatewayInterface','growth.response-classification.v1',
] as $needle){
    $assert(str_contains($services,$needle),'Response DI/config missing: '.$needle);
}
$assert(str_contains($docker,'GROWTH_ENGAGEMENT_RESPONSE_WEBHOOK_SECRET'),'Response webhook deployment secret missing.');

$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$template=$read('app/Interfaces/Web/View/growth/candidate.phtml');
$assert(str_contains($page,"'engagement_responses'=>"),'Candidate SSR must include response brief.');
$assert(str_contains($api,'engagementResponses('),'Growth API must expose candidate responses.');
foreach(['Conversation signals','Inbound responses','Classification pending durable processing.','advisory only'] as $needle){
    $assert(str_contains($template,$needle),'Candidate response UI missing: '.$needle);
}

$readme=$read('app/Domains/Growth/README.md');
foreach([
    'V0.45 — Inbound Response & Conversation Signals',
    'AI classification is not required to stop outreach.',
    'is advisory only.',
] as $needle){
    $assert(str_contains($readme,$needle),'V0.45 documentation missing: '.$needle);
}

echo "Growth V0.45 Inbound Responses architecture: OK\n";
