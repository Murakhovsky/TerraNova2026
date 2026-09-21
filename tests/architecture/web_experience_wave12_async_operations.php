<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/Kernel/Queue/AsyncOperationStatus.php',
    'app/Kernel/Queue/AsyncOperationProjection.php',
    'app/Kernel/Queue/Contract/AsyncOperationReadModelInterface.php',
    'app/Kernel/Queue/Event/AsyncOperationChanged.php',
    'app/Infrastructure/Platform/ReadModel/MySql/MysqlAsyncOperationReadModel.php',
    'symfony/src/Web/Experience/Async/AsyncOperationWebProvider.php',
    'symfony/src/Web/Experience/Async/AsyncOperationRealtimeEventConsumer.php',
    'symfony/src/Web/Experience/Async/ActivityCenterController.php',
    'symfony/src/Web/Experience/Async/RetryAsyncOperationController.php',
    'symfony/src/Application/Operations/Command/RetryAsyncOperationCommand.php',
    'symfony/src/Application/Operations/Command/RetryAsyncOperationCommandHandler.php',
    'symfony/src/Command/AsyncOperationsSmokeCommand.php',
    'symfony/templates/experience/async/activity_center_frame.html.twig',
    'symfony/assets/styles/async-operations.css',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.13 Async Operations artifact is missing: ' . $relative);
    }
}

$contract = (string) file_get_contents(
    $root . '/app/Kernel/Queue/Contract/AsyncOperationReadModelInterface.php',
);
foreach (['AsyncOperationProjection', 'recentForOrganization(', 'find('] as $marker) {
    if (!str_contains($contract, $marker)) {
        throw new RuntimeException('Async operation read contract is missing: ' . $marker);
    }
}
foreach (['App\\Web', 'Infrastructure\\', 'PDO', 'Doctrine\\'] as $forbidden) {
    if (str_contains($contract, $forbidden)) {
        throw new RuntimeException('Kernel async operation read contract leaked implementation dependency: ' . $forbidden);
    }
}

$projection = (string) file_get_contents($root . '/app/Kernel/Queue/AsyncOperationProjection.php');
foreach ([
    'AsyncOperationStatus $status',
    'public ?int $progress = null',
    'public ?string $actorId = null',
    'public ?string $entityType = null',
    'public ?string $entityId = null',
    'public ?string $error = null',
    'public bool $retryScheduled = false',
    'public bool $canRetry = false',
] as $marker) {
    if (!str_contains($projection, $marker)) {
        throw new RuntimeException('AsyncOperation projection is missing: ' . $marker);
    }
}

$readModel = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/ReadModel/MySql/MysqlAsyncOperationReadModel.php',
);
foreach ([
    'implements AsyncOperationReadModelInterface',
    'FROM cos_jobs j',
    'j.organization_id=:organization_id',
    'cos_actions a',
    'AsyncOperationStatus::Running',
    'AsyncOperationStatus::Completed',
    "'FAILED', 'DEAD'",
] as $marker) {
    if (!str_contains($readModel, $marker)) {
        throw new RuntimeException('Async operation MySQL projection is missing: ' . $marker);
    }
}

$provider = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Async/AsyncOperationWebProvider.php',
);
foreach ([
    'implements ActivityProviderInterface, NotificationProviderInterface',
    'recentForOrganization($context->organizationId',
    "id: 'async.operation.'",
    "id: 'async.operation.failure.'",
] as $marker) {
    if (!str_contains($provider, $marker)) {
        throw new RuntimeException('Async operation Web provider is missing: ' . $marker);
    }
}
foreach (['PDO', 'Doctrine\\', 'cos_jobs', 'fetch('] as $forbidden) {
    if (str_contains($provider, $forbidden)) {
        throw new RuntimeException('Async operation Web provider crossed presentation boundary: ' . $forbidden);
    }
}

$catalog = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/WebExtensionContextCatalog.php',
);
foreach ([
    'public function activity(',
    'public function notifications(',
    '$this->asyncOperations->activities(',
    '$this->providers->activity()',
    '$this->asyncOperations->notifications(',
    '$this->providers->notifications()',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('Unified Activity/Notification catalog is missing: ' . $marker);
    }
}

$queue = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/Persistence/MySql/Queue/MysqlJobQueue.php',
);
foreach ([
    'EventBus',
    'TransactionManagerInterface',
    '$this->transactions->transactional(',
    '$this->publishChanged(',
    'AsyncOperationChanged::create(',
] as $marker) {
    if (!str_contains($queue, $marker)) {
        throw new RuntimeException('Queue durable lifecycle event boundary is missing: ' . $marker);
    }
}
foreach (['App\\Web\\', 'Mercure', 'RealtimeStreamPublisher'] as $forbidden) {
    if (str_contains($queue, $forbidden)) {
        throw new RuntimeException('Canonical Queue leaked Web realtime dependency: ' . $forbidden);
    }
}

$event = (string) file_get_contents($root . '/app/Kernel/Queue/Event/AsyncOperationChanged.php');
foreach ([
    "TYPE = 'kernel.queue.operation.changed'",
    "aggregateType: 'async_operation'",
    "'job_id' => \$jobId",
] as $marker) {
    if (!str_contains($event, $marker)) {
        throw new RuntimeException('Async operation durable event is missing: ' . $marker);
    }
}

$consumer = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Async/AsyncOperationRealtimeEventConsumer.php',
);
foreach ([
    'implements DurableEventConsumerInterface',
    'AsyncOperationChanged::TYPE',
    'RealtimeStreamPublisher',
    'organization($event->organizationId)',
    'async_operation_signal.stream.html.twig',
] as $marker) {
    if (!str_contains($consumer, $marker)) {
        throw new RuntimeException('Async operation durable realtime consumer is missing: ' . $marker);
    }
}

$retryController = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Async/RetryAsyncOperationController.php',
);
foreach (['CommandBusInterface', 'SessionCsrfValidator', 'TenantPermissions::MANAGE', 'RetryAsyncOperationCommand'] as $marker) {
    if (!str_contains($retryController, $marker)) {
        throw new RuntimeException('Async retry server boundary is missing: ' . $marker);
    }
}
foreach (['JobQueueInterface', 'PDO', 'Doctrine\\', 'replayDead('] as $forbidden) {
    if (str_contains($retryController, $forbidden)) {
        throw new RuntimeException('Async retry controller bypassed Application boundary: ' . $forbidden);
    }
}

$retryHandler = (string) file_get_contents(
    $root . '/symfony/src/Application/Operations/Command/RetryAsyncOperationCommandHandler.php',
);
foreach ([
    'AsyncOperationReadModelInterface',
    'JobQueueInterface',
    'if (!$operation->canRetry)',
    'replayDead($command->organizationId, $command->operationId)',
] as $marker) {
    if (!str_contains($retryHandler, $marker)) {
        throw new RuntimeException('Async retry command handler is missing: ' . $marker);
    }
}

$shell = (string) file_get_contents($root . '/symfony/templates/experience/workspace_shell.html.twig');
foreach ([
    "path('cos_web_activity_center')",
    'data-action="click->workspace-shell#openNotifications"',
    'data-action="click->workspace-shell#openActivityCenter"',
    'id="cos-activity-center-frame"',
    'id="cos-async-operation-signal"',
] as $marker) {
    if (!str_contains($shell, $marker)) {
        throw new RuntimeException('Shell Activity Center integration is missing: ' . $marker);
    }
}

$browser = (string) file_get_contents($root . '/symfony/assets/controllers/workspace_shell_controller.js');
foreach (['openActivityCenter()', 'openNotifications()', 'activityCenterLoaded()', 'refreshActivityCenter()'] as $marker) {
    if (!str_contains($browser, $marker)) {
        throw new RuntimeException('Shell async operation behavior is missing: ' . $marker);
    }
}
foreach (['fetch(', 'axios', 'localStorage', 'sessionStorage', 'EventSource('] as $forbidden) {
    if (str_contains($browser, $forbidden)) {
        throw new RuntimeException('Shell Activity Center owns forbidden async transport/state: ' . $forbidden);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'cos_web_activity_center:',
    'path: /workspace/activity-center',
    'cos_web_async_operation_retry:',
] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Activity Center route is missing: ' . $marker);
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Kernel\\Queue\\Contract\\AsyncOperationReadModelInterface:',
    'Kernel\\Queue\\Contract\\JobLifecycleObserverInterface:',
] as $marker) {
    if (!str_contains($services, $marker)) {
        throw new RuntimeException('Async operations DI wiring is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/AsyncOperationsSmokeCommand.php');
foreach ([
    "name: 'cos:web:async-operations:smoke'",
    'RetryAsyncOperationCommand',
    'AsyncOperationWebProvider',
    'Activity Center runtime marker',
] as $marker) {
    if (!str_contains($smoke, $marker)) {
        throw new RuntimeException('Async operations runtime smoke is missing: ' . $marker);
    }
}

echo "Wave 12.13 Async Operations passed.\n";
