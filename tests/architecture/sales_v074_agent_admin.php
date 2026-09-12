<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) throw new RuntimeException('Cannot read ' . $path);
    return $content;
};
$must = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$migration = $read('app/migrations/20260911_000032_sales_v074_agent_administration.sql');
$must(str_contains($migration, 'CREATE TABLE IF NOT EXISTS cos_agent_configurations'), 'Agent configuration must use the generic COS configuration table.');
$must(!str_contains($migration, 'sales_agents'), 'Sales must not create a duplicate Sales agent runtime table.');
$must(str_contains($migration, 'configuration_version'), 'Agent configuration must be versioned.');
$must(str_contains($migration, "ownership ENUM('SYSTEM', 'ADMIN')"), 'Agent configuration must preserve SYSTEM/ADMIN ownership.');

$definition = $read('app/Kernel/Agent/AgentDefinition.php');
foreach (['confidenceThreshold', 'maxActionsPerRun', 'contextSources', 'configurationManaged'] as $needle) {
    $must(str_contains($definition, $needle), 'AgentDefinition missing configurable runtime boundary: ' . $needle);
}

$validator = $read('app/Kernel/Agent/Service/StructuredDecisionValidator.php');
$must(str_contains($validator, '$agent->maxActionsPerRun'), 'Maximum actions must be code-owned per AgentDefinition.');
$must(!str_contains($validator, 'count($actions) > 10'), 'Validator must not retain the legacy hard-coded max 10 actions.');

$runtime = $read('app/Kernel/Agent/Service/AgentRuntime.php');
$must(str_contains($runtime, 'AgentConfigurationProviderInterface'), 'Kernel AgentRuntime must resolve tenant agent configuration.');
$must(str_contains($runtime, '$result->confidence >= $agent->confidenceThreshold'), 'Confidence threshold must gate action proposals.');
$must(str_contains($runtime, 'selectContext'), 'Configured context sources must be enforced before the LLM call.');
$must(str_contains($runtime, 'configurationManaged'), 'Read-only test must be able to bypass a second configuration resolution safely.');

$agent = $read('app/Domains/Sales/Automation/Agent/SalesIntelligenceAgent.php');
foreach (['HARD_ALLOWED_ACTIONS', 'CONTEXT_SOURCES', 'MAX_ACTIONS_PER_RUN', 'systemContract', 'defaultRuntimeConfiguration'] as $needle) {
    $must(str_contains($agent, $needle), 'Sales Intelligence code-owned contract missing: ' . $needle);
}

$admin = $read('app/Infrastructure/Platform/Persistence/MySql/Configuration/MysqlSalesAgentAdministration.php');
$must(str_contains($admin, 'CONFIGURATION_CONFLICT'), 'Agent Admin must implement optimistic locking.');
$must(str_contains($admin, "configuration_type=\"AGENT\""), 'Agent Admin must read canonical AGENT revisions.');
$must(str_contains($admin, '"AGENT"'), 'Agent Admin must write AGENT revisions.');
$must(str_contains($admin, 'system_update_available=1'), 'Provisioning must surface system updates without overwriting ADMIN configuration.');
$must(str_contains($admin, 'array_diff($actions, SalesIntelligenceAgent::HARD_ALLOWED_ACTIONS)'), 'Admin allowed actions must be a subset of the code-owned allowlist.');
$must(str_contains($admin, 'self::CODE_OWNED_FIELDS'), 'Code-owned safety/schema fields must be rejected from Admin updates.');
$must(str_contains($admin, "[],\n            \$effective->defaultExecutionMode"), 'Read-only test must use an empty action allowlist.');
$must(str_contains($admin, "0,\n            false,"), 'Read-only test must enforce maxActions=0 and disable configuration re-resolution.');
$must(str_contains($admin, "'action_execution' => 'DISABLED'"), 'Read-only test must explicitly report action execution disabled.');

$routes = $read('app/Interfaces/Web/Routing/SalesRoutes.php');
foreach (['/sales/admin/agents', '/api/sales/admin/agents', '/test', '/revisions'] as $needle) {
    $must(str_contains($routes, $needle), 'Sales Agent Administration route missing: ' . $needle);
}

// Sales owns the tenant configuration provider; Kernel owns the generic runtime
// composition. This preserves the original boundary without a domain bootstrap override.
$salesBootstrap = $read('app/Bootstrap/SalesAgentServices.php');
$kernelBootstrap = $read('app/Bootstrap/KernelServices.php');
$must(str_contains($salesBootstrap, "setShared('cosAgentConfigurationProvider'"), 'Sales must register the tenant Agent configuration provider.');
$must(!str_contains($salesBootstrap, "setShared('cosAgentRuntime'"), 'Sales must not override the Kernel-owned AgentRuntime.');
$must(str_contains($kernelBootstrap, "setShared('cosAgentRuntime'"), 'Configured runtime must remain the generic Kernel AgentRuntime service.');
$must(str_contains($kernelBootstrap, "getShared('cosAgentConfigurationProvider')"), 'Kernel AgentRuntime must consume the tenant configuration provider.');
$must(!str_contains($salesBootstrap, 'SalesAgentRuntime'), 'Sales must not introduce a duplicate SalesAgentRuntime.');

fwrite(STDOUT, "Sales V0.7.4 agent administration architecture contract passed.\n");
