<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$read = static function (string $path) use ($root): string {
    $file = $root . '/' . $path;
    if (!is_file($file)) {
        throw new RuntimeException('Kernel V0.10 runtime artifact is missing: ' . $path);
    }
    return (string) file_get_contents($file);
};

$client = $read('app/Kernel/Llm/GovernedStructuredLlmClient.php');
$repository = $read('app/Infrastructure/Llm/MysqlLlmGovernanceRepository.php');
$interface = $read('app/Kernel/Llm/LlmGovernanceRepositoryInterface.php');

foreach (['LlmProviderRegistry', 'LlmRoutingPolicy', 'LlmGovernanceRepositoryInterface', 'MetricsRecorderInterface'] as $needle) {
    if (!str_contains($client, $needle)) {
        throw new RuntimeException('Governed LLM client is missing runtime dependency: ' . $needle);
    }
}
foreach (['monthlyBudget', 'monthlySpend', 'synchronizedBudget', 'record'] as $needle) {
    if (!str_contains($interface, $needle)) {
        throw new RuntimeException('LLM governance contract is missing: ' . $needle);
    }
}
foreach (['GET_LOCK', 'RELEASE_LOCK', 'cos_llm_budgets', 'cos_llm_usage'] as $needle) {
    if (!str_contains($repository, $needle)) {
        throw new RuntimeException('MySQL LLM governance repository is missing atomic budget coordination: ' . $needle);
    }
}
if (!str_contains($client, 'synchronizedBudget')) {
    throw new RuntimeException('Budgeted LLM execution must use an atomic serialized budget section.');
}
if (strpos($client, '$this->assertBudget($request);') > strpos($client, '$this->recordSuccess(')) {
    throw new RuntimeException('LLM budget must be checked before provider usage is settled.');
}

echo "COS Kernel V0.10 LLM runtime governance invariant passed.\n";
