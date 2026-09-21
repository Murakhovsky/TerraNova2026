<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Workspace/WorkspaceSlot.php',
    'symfony/src/Web/Experience/Workspace/WorkspaceViewModel.php',
    'symfony/src/Web/Experience/Workspace/WorkspaceCompositionResolver.php',
    'symfony/src/Web/Experience/Component/CosWorkspace.php',
    'symfony/src/Web/Experience/Component/CosWorkspaceHeader.php',
    'symfony/src/Web/Experience/Component/CosContextPanel.php',
    'symfony/src/Web/Experience/Component/CosActivityPanel.php',
    'symfony/src/Web/Experience/Component/CosAIContext.php',
    'symfony/templates/components/experience/cos_workspace.html.twig',
    'symfony/templates/components/experience/cos_workspace_header.html.twig',
    'symfony/assets/controllers/workspace_platform_controller.js',
    'symfony/assets/styles/workspace-platform.css',
    'symfony/src/Command/WorkspacePlatformSmokeCommand.php',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.10 Workspace Platform file is missing: ' . $relative);
    }
}

$slot = (string) file_get_contents($root . '/symfony/src/Web/Experience/Workspace/WorkspaceSlot.php');
foreach ([
    "'header'",
    "'primary_actions'",
    "'navigation'",
    "'tabs'",
    "'main'",
    "'sidebar'",
    "'activity'",
    "'documents'",
    "'ai'",
    "'footer'",
] as $marker) {
    if (!str_contains($slot, $marker)) {
        throw new RuntimeException('Canonical Workspace slot is missing: ' . $marker);
    }
}

$resolver = (string) file_get_contents($root . '/symfony/src/Web/Experience/Workspace/WorkspaceCompositionResolver.php');
foreach ([
    'WebExtensionCatalog',
    'UIActionResolver',
    'WORKSPACE_PRIMARY',
    'WORKSPACE_SECONDARY',
    'workspaceExtensions($workspaceId)',
    'organizationId()->value()',
    'role()->value()',
] as $marker) {
    if (!str_contains($resolver, $marker)) {
        throw new RuntimeException('Workspace composition contract is missing: ' . $marker);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'Repository', 'HttpClientInterface', '/api/'] as $forbidden) {
    if (str_contains($resolver, $forbidden)) {
        throw new RuntimeException('Workspace composition leaked business/data dependency: ' . $forbidden);
    }
}

$extension = (string) file_get_contents($root . '/symfony/src/Web/Experience/Extension/Model/WorkspaceExtension.php');
foreach (['WorkspaceSlot $slot', 'public array $props'] as $marker) {
    if (!str_contains($extension, $marker)) {
        throw new RuntimeException('WorkspaceExtension is missing typed slot/props contract: ' . $marker);
    }
}

$catalog = (string) file_get_contents($root . '/symfony/src/Web/Experience/Extension/WebExtensionContextCatalog.php');
if (!str_contains($catalog, 'workspaceExtensions(string $workspaceId)')) {
    throw new RuntimeException('Context-bound extension catalog does not aggregate Workspace slot extensions.');
}

$salesProvider = (string) file_get_contents($root . '/symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php');
foreach ([
    'WorkspaceExtensionProviderInterface',
    'WorkspaceSlot::Sidebar',
    'WorkspaceSlot::Activity',
    'WorkspaceSlot::Ai',
] as $marker) {
    if (!str_contains($salesProvider, $marker)) {
        throw new RuntimeException('Sales Workspace extension contribution is missing: ' . $marker);
    }
}

$salesManifest = (string) file_get_contents($root . '/app/Domains/Sales/module.php');
if (!str_contains($salesManifest, "'web.workspace.extensions' => ['salesNavigationContributor']")) {
    throw new RuntimeException('Sales module does not own its Workspace extension contribution.');
}

$template = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_workspace.html.twig');
foreach ([
    'data-controller="workspace-platform"',
    '<twig:CosWorkspaceHeader',
    '<twig:CosContextPanel',
    '<twig:CosActivityPanel',
    '<twig:CosAIContext',
    "workspace.extensions('main')",
    "workspace.extensions('documents')",
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Workspace component contract is missing: ' . $marker);
    }
}
foreach (['Domains\\', 'Doctrine\\', '/api/'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('Workspace Twig leaked implementation/business dependency: ' . $forbidden);
    }
}

$controller = (string) file_get_contents($root . '/symfony/assets/controllers/workspace_platform_controller.js');
foreach (['cos:workspace-action', 'workspaceActionId', 'entityKey'] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Workspace browser event contract is missing: ' . $marker);
    }
}
foreach (['fetch(', 'axios', '/api/', 'localStorage', 'sessionStorage'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Workspace controller contains forbidden transport/persistence: ' . $forbidden);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/workspace-platform.css');
foreach ([
    '.cos-workspace__header',
    '.cos-workspace__layout',
    '.cos-workspace__rail',
    '.cos-workspace-panel',
    '@media (max-width: 760px)',
] as $selector) {
    if (!str_contains($styles, $selector)) {
        throw new RuntimeException('Workspace responsive style contract is missing: ' . $selector);
    }
}
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $styles) === 1 || str_contains($styles, '--tn-')) {
    throw new RuntimeException('Workspace Platform styles must use canonical COS semantic tokens.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_web_workspace_platform_preview:', 'path: /dev/workspace'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Workspace Platform preview route is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/WorkspacePlatformSmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:web:workspace:smoke'")) {
    throw new RuntimeException('Workspace Platform runtime smoke is missing.');
}

echo "Wave 12.10 Workspace Platform passed.\n";
