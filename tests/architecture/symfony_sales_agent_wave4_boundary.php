<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$sink = $read('symfony/src/Infrastructure/Automation/SymfonySalesActionProposalSink.php');
$handler = $read('symfony/src/Application/Sales/Command/RunSalesAgentCommandHandler.php');
$tool = $read('symfony/src/Infrastructure/Automation/SalesActionProposalTool.php');
$permission = $read('symfony/src/Infrastructure/Automation/SalesAgentToolPermissionChecker.php');
$messenger = $read('symfony/config/packages/messenger.yaml');
$services = $read('symfony/config/services.yaml');

foreach ([
    'RunSalesAgentCommand',
    '$this->commandBus->dispatch(new RunSalesAgentCommand(',
] as $needle) {
    if (!str_contains($sink, $needle)) {
        throw new RuntimeException('Wave 4 Symfony Agent cutover missing from proposal sink: ' . $needle);
    }
}
foreach (['AgentRunJobHandler', 'JobQueueInterface', '$legacyJobs', 'AGENT_RUN'] as $forbidden) {
    if (str_contains($sink, $forbidden)) {
        throw new RuntimeException('Wave 4 Symfony Agent path still depends on legacy queue: ' . $forbidden);
    }
}

foreach ([
    'AgentRuntime',
    'ToolRuntimeInterface',
    "'sales.action.propose'",
    'ownerOfAgent',
    'isEnabled',
] as $needle) {
    if (!str_contains($handler, $needle)) {
        throw new RuntimeException('Wave 4 Agent handler contract missing: ' . $needle);
    }
}
foreach (['Symfony\\', 'PDO', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE '] as $forbidden) {
    if (str_contains($handler, $forbidden)) {
        throw new RuntimeException('Wave 4 Agent Application handler crossed framework/persistence boundary: ' . $forbidden);
    }
}

foreach ([
    'ToolInterface',
    "'sales.action.propose'",
    'ToolEffect::WRITE',
    'ActionPolicyService',
    'CommandBusInterface',
    'ExecuteSalesActionCommand',
] as $needle) {
    if (!str_contains($tool, $needle)) {
        throw new RuntimeException('Wave 4 governed Sales Tool contract missing: ' . $needle);
    }
}
foreach (['Mysql', 'PDO', 'CrmGatewayInterface', 'DealRepositoryInterface'] as $forbidden) {
    if (str_contains($tool, $forbidden)) {
        throw new RuntimeException('Wave 4 Sales Tool bypasses Policy/Action ownership: ' . $forbidden);
    }
}

foreach ([
    'tool.sales.action.propose.execute',
    "ownerOfAgent",
    "ownerOfAction",
    "allowedActionTypes",
    "isEnabled",
] as $needle) {
    if (!str_contains($permission, $needle)) {
        throw new RuntimeException('Wave 4 Tool permission boundary missing: ' . $needle);
    }
}

if (!str_contains($messenger, "'App\\Application\\Sales\\Command\\RunSalesAgentCommand': async")) {
    throw new RuntimeException('Wave 4 Sales Agent command is not routed through async Messenger.');
}

foreach ([
    'Kernel\\Agent\\Service\\AgentRuntime:',
    'Kernel\\Tool\\Service\\ToolRuntime:',
    'Kernel\\Tool\\Service\\ToolRegistry:',
    'App\\Infrastructure\\Automation\\SalesActionProposalTool:',
    'App\\Application\\Sales\\Command\\RunSalesAgentCommandHandler:',
] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Wave 4 Symfony composition missing: ' . $needle);
    }
}

echo "Symfony Sales Wave 4 Agent -> Tool -> Sales boundaries passed.\n";
