<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$scheduler = $read('symfony/src/Scheduler/CosScheduleProvider.php');
$messenger = $read('symfony/config/packages/messenger.yaml');
$services = $read('symfony/config/services.yaml');
$outbox = $read('symfony/src/Infrastructure/Automation/SalesEventOutbox.php');
$sink = $read('symfony/src/Infrastructure/Automation/SymfonySalesActionProposalSink.php');
$runner = $read('app/Domains/Sales/Application/Service/SalesAutomationRunner.php');
$monitoring = $read('app/Domains/Sales/Application/Service/SalesMonitoringService.php');
$module = $read('app/Domains/Sales/Bootstrap/SalesDomainModule.php');

foreach ([
    'RunSalesAutomationCommand',
    "RecurringMessage::every(\n                '5 minutes'",
    'DrainSalesOutboxCommand',
    "RecurringMessage::every(\n                '1 minute'",
] as $needle) {
    if (!str_contains($scheduler, $needle)) {
        throw new RuntimeException('Wave 3 scheduler contract missing: ' . $needle);
    }
}

foreach ([
    'RunSalesAutomationCommand',
    'DrainSalesOutboxCommand',
    'ExecuteSalesActionCommand',
] as $command) {
    if (!str_contains($messenger, $command) || !str_contains($messenger, 'async')) {
        throw new RuntimeException('Wave 3 async routing missing: ' . $command);
    }
}

foreach ([
    "e.type LIKE 'sales.%'",
    "status IN ('PENDING','FAILED')",
    'FOR UPDATE SKIP LOCKED',
] as $needle) {
    if (!str_contains($outbox, $needle)) {
        throw new RuntimeException('Wave 3 Sales-only Outbox boundary missing: ' . $needle);
    }
}

foreach ([
    'ActionPolicyService',
    'ExecuteSalesActionCommand',
    'AgentRunJobHandler::TYPE',
] as $needle) {
    if (!str_contains($sink, $needle)) {
        throw new RuntimeException('Wave 3 action proposal sink contract missing: ' . $needle);
    }
}

foreach (['SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'Symfony\\', 'Infrastructure\\'] as $needle) {
    if (str_contains($runner, $needle)) {
        throw new RuntimeException('SalesAutomationRunner crossed Application boundary: ' . $needle);
    }
}

foreach ([
    'FollowupOverdue::TYPE',
    'SalesEventType::NO_ACTIVITY_DETECTED',
    'SalesEventType::DEAL_STUCK',
    'claimSignal',
] as $needle) {
    if (!str_contains($monitoring, $needle)) {
        throw new RuntimeException('Wave 3 canonical detector contract missing: ' . $needle);
    }
}

foreach ([
    'CreateLeadFollowupTaskHandler::TYPE',
    'new CreateLeadFollowupTaskHandler',
] as $needle) {
    if (!str_contains($module, $needle)) {
        throw new RuntimeException('Wave 3 Sales module ownership missing: ' . $needle);
    }
}

foreach ([
    'Kernel\\Rule\\Service\\RuleEngineEventHandler:',
    'Kernel\\Policy\\Service\\ActionPolicyService:',
    'Kernel\\Action\\Service\\ActionService:',
    'Domains\\Sales\\Automation\\Event\\SalesHistoricalEventConsumer:',
    'App\\Infrastructure\\Automation\\SalesEventOutbox:',
] as $needle) {
    if (!str_contains($services, $needle)) {
        throw new RuntimeException('Wave 3 Symfony composition missing canonical COS runtime: ' . $needle);
    }
}

foreach (glob($root . '/app/Domains/Sales/**/*.php') ?: [] as $ignored) {
    // PHP glob ** is not portable; explicit new Wave 3 sources are checked below.
}
foreach ([
    'app/Domains/Sales/Application/Service/SalesAutomationRunner.php',
    'app/Domains/Sales/Application/UseCase/ScheduleLeadFollowup.php',
    'app/Domains/Sales/Automation/Action/CreateLeadFollowupTaskHandler.php',
] as $path) {
    $source = $read($path);
    if (str_contains($source, 'Symfony\\')) {
        throw new RuntimeException('Sales Domain/Application must stay framework-independent: ' . $path);
    }
}

echo "Symfony Sales Wave 3 automation boundaries passed.\n";
