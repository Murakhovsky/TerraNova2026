<?php
declare(strict_types=1);

function expectGrowthV0460Architecture(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>file_get_contents($root.'/'.$path)?:'';

$module=require $root.'/app/Domains/Growth/module.php';
expectGrowthV0460Architecture(version_compare((string)$module['version'],'0.46.0','>='),'Growth module version must be >= 0.46.0.');
expectGrowthV0460Architecture(version_compare((string)$module['schema_version'],'0.46.0','>='),'Growth schema version must be >= 0.46.0.');
foreach([
    'growth.engagement.conversation_routing','growth.engagement.contact_suppression',
    'growth.engagement.routing_target.sales','growth.engagement.routing_target.service',
] as $capability){
    expectGrowthV0460Architecture(in_array($capability,$module['contributions']['capabilities']??[],true),'V0.46 capability missing: '.$capability);
}
expectGrowthV0460Architecture(
    in_array('app/migrations/20260926_000111_growth_v0460_conversation_routing.sql',$module['contributions']['migration_files']??[],true),
    'V0.46 migration missing from manifest.'
);

$migration=$read('app/migrations/20260926_000111_growth_v0460_conversation_routing.sql');
foreach(['tn_growth_conversation_routes','tn_growth_engagement_suppressions','uq_growth_conversation_route_response',"installed_version='0.46.0'"] as $needle){
    expectGrowthV0460Architecture(str_contains($migration,$needle),'V0.46 migration invariant missing: '.$needle);
}
$ownership=$read('app/Infrastructure/Platform/Persistence/TableOwnership.php');
foreach(['tn_growth_conversation_routes','tn_growth_engagement_suppressions'] as $needle){
    expectGrowthV0460Architecture(str_contains($ownership,$needle),'V0.46 table ownership missing: '.$needle);
}

$policy=$read('app/Domains/Growth/Domain/GrowthConversationRoutingPolicy.php');
foreach(['growth-conversation-routing-v1','MIN_CONFIDENCE','Suppression','NoAction','Partnership','HumanReview'] as $needle){
    expectGrowthV0460Architecture(str_contains($policy,$needle),'Conversation routing policy missing: '.$needle);
}

$service=$read('app/Domains/Growth/Application/Service/GrowthConversationRoutingService.php');
foreach([
    'GrowthConversationRoutingPolicy','routeForResponse','routeForClassification','lockById','growth-conversation-route:',
    'ENGAGEMENT_RESPONSE_ROUTE_DECIDED','ENGAGEMENT_RESPONSE_ROUTED','ENGAGEMENT_RESPONSE_ROUTE_FAILED',
] as $needle){
    expectGrowthV0460Architecture(str_contains($service,$needle),'Conversation routing service missing: '.$needle);
}
expectGrowthV0460Architecture(!str_contains($service,'StructuredLlmClientInterface'),'Routing authority must not call an LLM.');
expectGrowthV0460Architecture(!str_contains($service,"['body']"),'Routing authority must not copy raw inbound body cross-domain.');

$consumer=$read('app/Domains/Growth/Automation/Event/GrowthConversationRoutingConsumer.php');
expectGrowthV0460Architecture(str_contains($consumer,'ENGAGEMENT_RESPONSE_CLASSIFIED'),'Routing consumer must react to classified responses.');
expectGrowthV0460Architecture(str_contains($consumer,'growth.conversation-routing.v1'),'Routing consumer identity missing.');

$guard=$read('app/Domains/Growth/Application/Service/GrowthOutreachSequenceGuard.php');
expectGrowthV0460Architecture(str_contains($guard,'contact_suppressed'),'Central outreach guard must enforce contact suppression.');
expectGrowthV0460Architecture(str_contains($guard,'isContactSuppressed'),'Suppression must be repository-backed, not a cosmetic route state.');

$sales=$read('app/Domains/Growth/Infrastructure/Routing/SalesGrowthConversationRoutingTarget.php');
$serviceTarget=$read('app/Domains/Growth/Infrastructure/Routing/ServiceGrowthConversationRoutingTarget.php');
expectGrowthV0460Architecture(str_contains($sales,'SalesWriteServiceFactoryInterface')&&str_contains($sales,'createLead'),'Sales routing adapter missing.');
expectGrowthV0460Architecture(str_contains($serviceTarget,'ServiceApplicationBoundary')&&str_contains($serviceTarget,'createRequest'),'Service routing adapter missing.');

$prompt=$read('app/Domains/Growth/Application/AI/GrowthResponseClassificationPrompt.php');
expectGrowthV0460Architecture(str_contains($prompt,'recommended_next_owner is advisory only'),'LLM next-owner output must remain advisory.');
expectGrowthV0460Architecture(str_contains($prompt,'growth-response-classification-v2'),'V0.46 classifier semantics require a prompt version bump.');

$services=$read('symfony/config/services.yaml');
foreach(['growth.conversation_routing_target','GrowthConversationRoutingBoundary','growth.conversation-routing.v1'] as $needle){
    expectGrowthV0460Architecture(str_contains($services,$needle),'V0.46 DI/durable consumer wiring missing: '.$needle);
}

$routes=$read('symfony/config/routes.yaml');
$api=$read('symfony/src/Http/Api/V1/Controller/GrowthApiController.php');
$page=$read('symfony/src/Web/Growth/GrowthPageController.php');
$view=$read('app/Interfaces/Web/View/growth/candidate.phtml');
expectGrowthV0460Architecture(str_contains($routes,'/engagement/routing'),'Conversation routing API route missing.');
expectGrowthV0460Architecture(str_contains($api,'engagementRouting'),'Conversation routing API method missing.');
expectGrowthV0460Architecture(str_contains($page,'conversation_routing'),'Candidate SSR routing projection missing.');
expectGrowthV0460Architecture(str_contains($view,'Authoritative route'),'Candidate UI must distinguish routing authority from advisory classification.');

$readme=$read('app/Domains/Growth/README.md');
foreach(['V0.46 — Conversation Routing Authority','AI classification remains advisory','Partnership'] as $needle){
    expectGrowthV0460Architecture(str_contains($readme,$needle),'V0.46 documentation missing: '.$needle);
}

echo "Growth V0.46 Conversation Routing Authority architecture: OK\n";
