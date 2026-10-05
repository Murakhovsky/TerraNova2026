<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$openai = (string) file_get_contents($root.'/app/Infrastructure/Llm/OpenAiResponsesStructuredLlmClient.php');
$governed = (string) file_get_contents($root.'/app/Kernel/Llm/GovernedStructuredLlmClient.php');
$pricing = (string) file_get_contents($root.'/app/Infrastructure/Llm/PlatformSettingsLlmPricingResolver.php');
$catalog = (string) file_get_contents($root.'/app/Infrastructure/Llm/OpenAiModelCatalog.php');
$usage = (string) file_get_contents($root.'/app/Kernel/Llm/LlmUsageRecord.php');
$repository = (string) file_get_contents($root.'/app/Infrastructure/Llm/MysqlLlmGovernanceRepository.php');
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261005124000.php');
$runner = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Agent/EngineeringAgentRunner.php');
$runStore = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');
$readModel = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Observability/DoctrineEngineeringObservabilityReadModel.php');
$status = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringStatusService.php');
$template = (string) file_get_contents($root.'/symfony/templates/experience/engineering/feature.html.twig');
$services = (string) file_get_contents($root.'/symfony/config/services.yaml');

foreach (['input_tokens_details','cached_tokens','output_tokens_details','reasoning_tokens',"decoded['id']"] as $needle) {
    if (!str_contains($openai, $needle)) throw new RuntimeException('OpenAI detailed usage missing '.$needle);
}
foreach (['withResolvedCost','LlmPricingResolverInterface','cachedInputTokens','reasoningTokens','providerRequestId','costSource','pricingVersion'] as $needle) {
    if (!str_contains($governed, $needle)) throw new RuntimeException('Governed LLM accounting missing '.$needle);
}
foreach (['llm_pricing','input_per_million','cached_input_per_million','output_per_million','CALCULATED_','OpenAiModelCatalog::pricingCatalog'] as $needle) {
    if (!str_contains($pricing, $needle)) throw new RuntimeException('Tenant LLM pricing resolver missing '.$needle);
}
foreach (['gpt-6-astra','gpt-6.1-sol','gpt-6-luna','10.0','50.0','2.0','0.1','0.5','1_050_000','128_000'] as $needle) {
    if (!str_contains($catalog, $needle)) throw new RuntimeException('Built-in GPT-6 model catalog missing '.$needle);
}
foreach (['cachedInputTokens','reasoningTokens','costSource','pricingVersion','providerRequestId'] as $needle) {
    if (!str_contains($usage, $needle)) throw new RuntimeException('Usage record provenance missing '.$needle);
}
foreach (['cached_input_tokens','reasoning_tokens','cost_source','pricing_version','provider_request_id'] as $needle) {
    if (!str_contains($repository.$migration.$readModel, $needle)) throw new RuntimeException('Detailed LLM persistence/read model missing '.$needle);
}
foreach (['runCorrelationId', "':agent:'"] as $needle) {
    if (!str_contains($runner, $needle) || !str_contains($runStore, $needle)) {
        throw new RuntimeException('Per-agent LLM correlation missing '.$needle);
    }
}
foreach (['attachLedgerUsage','ledger_usage','cost_sources','pricing_versions'] as $needle) {
    if (!str_contains($status.$template, $needle)) throw new RuntimeException('Agent-run ledger attribution missing '.$needle);
}
foreach (['Cached','Reasoning','Provider request','Джерело ціни'] as $needle) {
    if (!str_contains($template, $needle)) throw new RuntimeException('Engineering detailed usage UI missing '.$needle);
}
if (!str_contains($services, 'Kernel\Llm\LlmPricingResolverInterface')) {
    throw new RuntimeException('LLM pricing resolver is not wired.');
}

echo "Engineering detailed LLM accounting contract passed.\n";
