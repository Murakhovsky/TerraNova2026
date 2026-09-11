<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Module\KernelVersion;

if (version_compare(KernelVersion::VERSION, '0.10.0', '<')) {
    throw new RuntimeException('COS Kernel LLM governance requires Kernel 0.10.0+.');
}

foreach ([
    'app/Kernel/Llm/GovernedStructuredLlmClient.php',
    'app/Kernel/Llm/LlmProviderRegistry.php',
    'app/Kernel/Llm/LlmRoutingPolicy.php',
    'app/Kernel/Llm/LlmGovernanceRepositoryInterface.php',
    'app/Kernel/Llm/LlmUsageRecord.php',
    'app/Infrastructure/Llm/MysqlLlmGovernanceRepository.php',
    'app/migrations/20260911_000041_cos_kernel_v010_llm_governance.sql',
] as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Kernel V0.10 LLM governance artifact is missing: ' . $path);
    }
}

$governed = (string) file_get_contents($root . '/app/Kernel/Llm/GovernedStructuredLlmClient.php');
foreach (['routesFor(', 'monthlyBudget(', 'monthlySpend(', 'LlmBudgetExceededException', 'LlmProviderException', 'retryable', 'fallbackCount', 'llm.request.cost', 'llm.request.latency_ms'] as $needle) {
    if (!str_contains($governed, $needle)) {
        throw new RuntimeException('Governed LLM runtime is missing behavior: ' . $needle);
    }
}

$httpClient = (string) file_get_contents($root . '/app/Infrastructure/Llm/HttpStructuredLlmClient.php');
foreach (['LlmProviderException', '$lastStatus === 429', '$lastStatus >= 500', '$lastStatus === 0'] as $needle) {
    if (!str_contains($httpClient, $needle)) {
        throw new RuntimeException('HTTP LLM provider does not expose typed retry/fallback semantics: ' . $needle);
    }
}

$bootstrap = (string) file_get_contents($root . '/app/Bootstrap/InfrastructureServices.php');
foreach (['cosLlmProviderRegistry', 'cosLlmRoutingPolicy', 'cosLlmGovernanceRepository', 'GovernedStructuredLlmClient', 'LLM_FALLBACK_ENDPOINT', 'LLM_ROUTES_JSON', 'LLM_BUDGET_CURRENCY'] as $needle) {
    if (!str_contains($bootstrap, $needle)) {
        throw new RuntimeException('LLM governance composition is missing: ' . $needle);
    }
}

$diagnostic = (string) file_get_contents($root . '/app/Domains/Diagnostic/Infrastructure/AI/OpenAiGateway.php');
foreach (['organizationId: $request->organizationId', "useCase: 'diagnostic.'", 'correlationId: $request->diagnosticId'] as $needle) {
    if (!str_contains($diagnostic, $needle)) {
        throw new RuntimeException('Diagnostic AI is missing tenant-aware LLM governance context: ' . $needle);
    }
}

$agentRuntime = (string) file_get_contents($root . '/app/Kernel/Agent/Service/AgentRuntime.php');
foreach (['OrganizationAwareLlmClientInterface', 'structuredForOrganization(', '$invocation->organizationId', '$runId'] as $needle) {
    if (!str_contains($agentRuntime, $needle)) {
        throw new RuntimeException('Agent runtime is not connected to tenant-aware LLM governance: ' . $needle);
    }
}

$migration = (string) file_get_contents($root . '/app/migrations/20260911_000041_cos_kernel_v010_llm_governance.sql');
foreach (['cos_llm_budgets', 'cos_llm_usage', 'organization_id', 'monthly_limit', 'cost_amount', 'fallback_count'] as $needle) {
    if (!str_contains($migration, $needle)) {
        throw new RuntimeException('LLM governance persistence is missing: ' . $needle);
    }
}

echo "COS Kernel V0.10 LLM runtime governance architecture passed.\n";
