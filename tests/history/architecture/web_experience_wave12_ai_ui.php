<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'app/Kernel/Agent/AgentRunProjection.php',
    'app/Kernel/Agent/AgentActionProjection.php',
    'app/Kernel/Agent/Contract/AgentRunReadModelInterface.php',
    'app/Infrastructure/Platform/ReadModel/MySql/MysqlAgentRunReadModel.php',
    'symfony/src/Web/Experience/AI/UIContext.php',
    'symfony/src/Web/Experience/AI/UIContextAction.php',
    'symfony/src/Web/Experience/AI/UIContextFactory.php',
    'symfony/src/Web/Experience/AI/StructuredAgentResult.php',
    'symfony/src/Web/Experience/AI/StructuredAgentResultFactory.php',
    'symfony/src/Web/Experience/AI/AgentRunViewFactory.php',
    'symfony/src/Web/Experience/AI/AIPanelController.php',
    'symfony/src/Command/AIUIPlatformSmokeCommand.php',
    'symfony/templates/experience/ai/ai_panel_frame.html.twig',
    'symfony/assets/controllers/ai_action_controller.js',
    'symfony/assets/styles/ai-ui.css',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.14 AI UI artifact is missing: ' . $relative);
    }
}

$contract = (string) file_get_contents($root . '/app/Kernel/Agent/Contract/AgentRunReadModelInterface.php');
foreach (['AgentRunProjection', 'recentForOrganization(', 'recentForSubject(', 'actionsForRun('] as $marker) {
    if (!str_contains($contract, $marker)) {
        throw new RuntimeException('AgentRun read contract is missing: ' . $marker);
    }
}
foreach (['App\\Web', 'Infrastructure\\', 'PDO', 'Doctrine\\'] as $forbidden) {
    if (str_contains($contract, $forbidden)) {
        throw new RuntimeException('Kernel Agent read contract leaked implementation dependency: ' . $forbidden);
    }
}

$readModel = (string) file_get_contents($root . '/app/Infrastructure/Platform/ReadModel/MySql/MysqlAgentRunReadModel.php');
foreach ([
    'implements AgentRunReadModelInterface',
    'FROM cos_agent_runs',
    'organization_id=:organization_id',
    "a.source_type='AGENT'",
    'cos_actions a',
    'cos_approvals ap',
] as $marker) {
    if (!str_contains($readModel, $marker)) {
        throw new RuntimeException('AgentRun MySQL projection is missing: ' . $marker);
    }
}

$uiContext = (string) file_get_contents($root . '/symfony/src/Web/Experience/AI/UIContextFactory.php');
foreach ([
    'UIActionResolver',
    'UIActionPlacement::AI_PROPOSAL',
    'organization does not match',
    'role does not match',
    'Unknown UIContext workspace',
] as $marker) {
    if (!str_contains($uiContext, $marker)) {
        throw new RuntimeException('Headless UIContext contract is missing: ' . $marker);
    }
}
foreach (['PDO', 'Doctrine\\', 'cos_agent_runs', 'HttpClientInterface'] as $forbidden) {
    if (str_contains($uiContext, $forbidden)) {
        throw new RuntimeException('Headless UIContext crossed presentation boundary: ' . $forbidden);
    }
}

$viewFactory = (string) file_get_contents($root . '/symfony/src/Web/Experience/AI/AgentRunViewFactory.php');
foreach ([
    'actionsForRun(',
    'UIActionResolver',
    'UIActionPlacement::AI_PROPOSAL',
    'resourceId',
    'PendingApproval',
] as $marker) {
    if (!str_contains($viewFactory, $marker)) {
        throw new RuntimeException('Agent Action → UIAction projection is missing: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Experience/AI/AIPanelController.php');
foreach ([
    'TenantContextProviderInterface',
    'recentForSubject(',
    'recentForOrganization(',
    'UIContextFactory',
    "surface: 'ai'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('AI panel server boundary is missing: ' . $marker);
    }
}
foreach (['PDO', 'Doctrine\\', 'organization_id', 'fetch('] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('AI panel controller crossed server boundary: ' . $forbidden);
    }
}

$browser = (string) file_get_contents($root . '/symfony/assets/controllers/ai_action_controller.js');
foreach (['cos:workspace-action', "source: 'ai'", 'actionId', 'resourceId', 'entityKey'] as $marker) {
    if (!str_contains($browser, $marker)) {
        throw new RuntimeException('AI action browser event contract is missing: ' . $marker);
    }
}
foreach (['fetch(', 'axios', 'EventSource(', 'localStorage', 'sessionStorage', '/api/'] as $forbidden) {
    if (str_contains($browser, $forbidden)) {
        throw new RuntimeException('AI browser layer owns forbidden execution/state: ' . $forbidden);
    }
}

$templates = [
    'symfony/templates/experience/ai/ai_panel_frame.html.twig',
    'symfony/templates/components/experience/cos_agent_action.html.twig',
    'symfony/templates/components/experience/cos_agent_evidence.html.twig',
    'symfony/templates/components/experience/cos_agent_metric.html.twig',
    'symfony/templates/components/experience/cos_agent_recommendation.html.twig',
    'symfony/templates/components/experience/cos_agent_result.html.twig',
    'symfony/templates/components/experience/cos_agent_run.html.twig',
    'symfony/templates/components/experience/cos_agent_warning.html.twig',
];

foreach ($templates as $template) {
    $source = (string) file_get_contents($root . '/' . $template);
    if (str_contains($source, '|raw')) {
        throw new RuntimeException('Agent model output must never be rendered with Twig raw: ' . $template);
    }
}

$shell = (string) file_get_contents($root . '/symfony/templates/experience/workspace_shell.html.twig');
foreach ([
    "path('cos_web_ai_panel')",
    'data-action="click->workspace-shell#openAI"',
    'id="cos-ai-panel"',
    'id="cos-ai-panel-frame"',
] as $marker) {
    if (!str_contains($shell, $marker)) {
        throw new RuntimeException('Shell AI surface integration is missing: ' . $marker);
    }
}

$shellController = (string) file_get_contents($root . '/symfony/assets/controllers/workspace_shell_controller.js');
foreach (['openAI()', 'closeAI()', "querySelector('[data-workspace-id]')", "url.searchParams.set('entity'"] as $marker) {
    if (!str_contains($shellController, $marker)) {
        throw new RuntimeException('Shell AI context behavior is missing: ' . $marker);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_web_ai_panel:', 'path: /workspace/ai'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('AI panel route is missing: ' . $marker);
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'Infrastructure\\Platform\\ReadModel\\MySql\\MysqlAgentRunReadModel:',
    'Kernel\\Agent\\Contract\\AgentRunReadModelInterface:',
    'App\\Web\\Experience\\AI\\AIPanelController:',
] as $marker) {
    if (!str_contains($services, $marker)) {
        throw new RuntimeException('AI UI DI wiring is missing: ' . $marker);
    }
}

$runtimeActions = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Action/RuntimeUIActionProvider.php',
);
if (!str_contains($runtimeActions, "id: 'runtime.action.a' . \$action->id")) {
    throw new RuntimeException('Runtime Action ids must remain canonical when projected into AI UI.');
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/AIUIPlatformSmokeCommand.php');
foreach ([
    "name: 'cos:web:ai-ui:smoke'",
    "sourceType: 'AGENT'",
    'UIContextFactory',
    'AgentRunViewFactory',
    '&lt;script&gt;',
] as $marker) {
    if (!str_contains($smoke, $marker)) {
        throw new RuntimeException('AI UI runtime smoke is missing: ' . $marker);
    }
}

echo "Wave 12.14 AI UI Platform passed.\n";
