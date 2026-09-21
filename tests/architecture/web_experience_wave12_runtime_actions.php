<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/Kernel/Action/RuntimeActionProjection.php',
    'app/Kernel/Action/Contract/RuntimeActionReadModelInterface.php',
    'app/Infrastructure/Platform/ReadModel/MySql/MysqlRuntimeActionReadModel.php',
    'symfony/src/Web/Experience/Action/RuntimeUIActionProvider.php',
    'symfony/src/Command/RuntimeUIActionSmokeCommand.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.11 runtime Action artifact is missing: ' . $relative);
    }
}

$contract = (string) file_get_contents($root . '/app/Kernel/Action/Contract/RuntimeActionReadModelInterface.php');
foreach (['RuntimeActionProjection', 'forEntity(', 'array $targetTypes', 'string $targetId'] as $marker) {
    if (!str_contains($contract, $marker)) {
        throw new RuntimeException('Runtime Action read contract is missing: ' . $marker);
    }
}
foreach (['App\\Web', 'Infrastructure\\', 'PDO'] as $forbidden) {
    if (str_contains($contract, $forbidden)) {
        throw new RuntimeException('Kernel runtime Action read contract leaked implementation dependency: ' . $forbidden);
    }
}

$readModel = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/ReadModel/MySql/MysqlRuntimeActionReadModel.php',
);
foreach ([
    'implements RuntimeActionReadModelInterface',
    'a.organization_id=:organization_id',
    'a.target_id=:target_id',
    'a.target_type IN (',
    'cos_approvals',
    'ActionStatus::tryFrom',
] as $marker) {
    if (!str_contains($readModel, $marker)) {
        throw new RuntimeException('Runtime Action MySQL projection is missing: ' . $marker);
    }
}

$provider = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Action/RuntimeUIActionProvider.php',
);
foreach ([
    'RuntimeActionReadModelInterface',
    'runtime.action.',
    'runtime.approval.',
    'operations.execute_action',
    'operations.approve',
    'operations.reject',
    'ActionStatus::PendingApproval',
    'ActionStatus::Queued',
    'ActionStatus::Running',
    'ActionStatus::Completed',
    'ActionStatus::Rejected',
    'UIActionDangerLevel::Critical',
    'UIActionConfirmation::stepUp',
    'TenantPermissions::MANAGE',
] as $marker) {
    if (!str_contains($provider, $marker)) {
        throw new RuntimeException('Runtime UIAction projection contract is missing: ' . $marker);
    }
}
foreach (['PDO', 'Doctrine\\', 'Repository', 'HttpClientInterface', '/api/'] as $forbidden) {
    if (str_contains($provider, $forbidden)) {
        throw new RuntimeException('Runtime UIAction provider crossed presentation boundary: ' . $forbidden);
    }
}

$uiAction = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIAction.php');
foreach (['public ?string $resourceId = null', 'resourceId: $this->resourceId'] as $marker) {
    if (!str_contains($uiAction, $marker)) {
        throw new RuntimeException('UIAction runtime resource identity contract is missing: ' . $marker);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Web/Experience/Action/UIActionRegistry.php');
foreach ([
    'RuntimeUIActionProvider $runtimeActions',
    '...$this->runtimeActions->actions($context, $entity)',
] as $marker) {
    if (!str_contains($registry, $marker)) {
        throw new RuntimeException('UIActionRegistry does not merge runtime Actions: ' . $marker);
    }
}

$template = (string) file_get_contents(
    $root . '/symfony/templates/components/experience/cos_workspace_header.html.twig',
);
if (!str_contains($template, 'data-workspace-action-resource-id="{{ action.resourceId')) {
    throw new RuntimeException('Workspace UI does not expose the server-owned runtime resource id.');
}

$controller = (string) file_get_contents(
    $root . '/symfony/assets/controllers/workspace_platform_controller.js',
);
if (!str_contains($controller, 'resourceId: trigger.dataset.workspaceActionResourceId')) {
    throw new RuntimeException('Workspace action event does not preserve runtime resource identity.');
}
foreach (['fetch(', 'axios', '/api/', 'operations.execute_action'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Browser attempted to own runtime Action execution: ' . $forbidden);
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Infrastructure\\Platform\\ReadModel\\MySql\\MysqlRuntimeActionReadModel:',
    'Kernel\\Action\\Contract\\RuntimeActionReadModelInterface:',
] as $marker) {
    if (!str_contains($services, $marker)) {
        throw new RuntimeException('Runtime Action projection DI wiring is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/RuntimeUIActionSmokeCommand.php');
foreach ([
    "name: 'cos:web:runtime-actions:smoke'",
    'ActionService',
    'UIActionRegistry',
    'RuntimeUIActionProvider',
    "new EntityRef('sales.deal'",
] as $marker) {
    if (!str_contains($smoke, $marker)) {
        throw new RuntimeException('Runtime Action smoke contract is missing: ' . $marker);
    }
}

echo "Wave 12.11 Runtime Actions passed.\n";
