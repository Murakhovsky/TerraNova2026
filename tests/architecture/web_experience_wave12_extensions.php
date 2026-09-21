<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$contracts = [
    'NavigationProviderInterface',
    'SearchProviderInterface',
    'CommandProviderInterface',
    'WorkspaceProviderInterface',
    'WorkspaceExtensionProviderInterface',
    'DashboardWidgetProviderInterface',
    'EntityLinkProviderInterface',
    'NotificationProviderInterface',
    'ActivityProviderInterface',
    'ActionProviderInterface',
];

foreach ($contracts as $contract) {
    $path = $root . '/symfony/src/Web/Experience/Extension/Contract/' . $contract . '.php';
    if (!is_file($path)) {
        throw new RuntimeException('Wave 12.5 provider contract is missing: ' . $contract);
    }

    $source = (string) file_get_contents($path);
    if (!str_contains($source, 'extends WebExtensionProviderInterface')) {
        throw new RuntimeException('Web provider contract does not extend marker interface: ' . $contract);
    }
}

$points = (string) file_get_contents($root . '/app/Kernel/Module/ModuleExtensionPoint.php');
foreach ([
    "WEB_NAVIGATION = 'web.navigation'",
    "WEB_SEARCH = 'web.search'",
    "WEB_COMMANDS = 'web.commands'",
    "WEB_WORKSPACE = 'web.workspace'",
    "WEB_WORKSPACE_EXTENSIONS = 'web.workspace.extensions'",
    "WEB_DASHBOARD_WIDGETS = 'web.dashboard_widgets'",
    "WEB_ENTITY_LINKS = 'web.entity_links'",
    "WEB_NOTIFICATIONS = 'web.notifications'",
    "WEB_ACTIVITY = 'web.activity'",
    "WEB_ACTIONS = 'web.actions'",
] as $point) {
    if (!str_contains($points, $point)) {
        throw new RuntimeException('Canonical Web extension point is missing: ' . $point);
    }
}

$providerExpectations = [
    'Sales' => [
        'service' => 'salesNavigationContributor',
        'class' => 'SalesWebProvider',
    ],
    'Property' => [
        'service' => 'propertyNavigationContributor',
        'class' => 'PropertyWebProvider',
    ],
    'Diagnostic' => [
        'service' => 'diagnosticNavigationContributor',
        'class' => 'DiagnosticWebProvider',
    ],
];

foreach ($providerExpectations as $domain => $expectation) {
    $manifest = (string) file_get_contents($root . '/app/Domains/' . $domain . '/module.php');

    foreach (['web.navigation', 'web.commands', 'web.workspace'] as $extensionPoint) {
        if (!str_contains($manifest, "'" . $extensionPoint . "' => ['" . $expectation['service'] . "']")) {
            throw new RuntimeException(sprintf(
                '%s manifest does not own %s through %s.',
                $domain,
                $extensionPoint,
                $expectation['service'],
            ));
        }
    }

    $providerPath = $root
        . '/symfony/src/Web/Experience/Extension/Provider/'
        . $expectation['class']
        . '.php';

    $provider = (string) file_get_contents($providerPath);

    foreach ([
        'NavigationProviderInterface',
        'CommandProviderInterface',
        'WorkspaceProviderInterface',
        "return '" . $expectation['service'] . "';",
    ] as $contract) {
        if (!str_contains($provider, $contract)) {
            throw new RuntimeException(sprintf(
                '%s does not satisfy provider contract: %s',
                $expectation['class'],
                $contract,
            ));
        }
    }

    foreach (['Doctrine\\', 'PDO', 'HttpClientInterface', 'fetch(', '/api/v1/'] as $forbidden) {
        if (str_contains($provider, $forbidden)) {
            throw new RuntimeException(sprintf(
                '%s provider contains forbidden data/application transport dependency: %s',
                $expectation['class'],
                $forbidden,
            ));
        }
    }
}

$services = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'cos.web.extension.provider',
    'Kernel\\Module\\ModuleExtensionRegistry:',
    'App\\Web\\Experience\\Extension\\WebExtensionProviderRegistry:',
    '!tagged_iterator cos.web.extension.provider',
    'salesNavigationContributor:',
    'propertyNavigationContributor:',
    'diagnosticNavigationContributor:',
] as $wiring) {
    if (!str_contains($services, $wiring)) {
        throw new RuntimeException('Web extension DI wiring is missing: ' . $wiring);
    }
}

$registry = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/WebExtensionProviderRegistry.php',
);
foreach ([
    'ModuleExtensionRegistry',
    'ActiveModuleResolver',
    'snapshot($context->organizationId)',
    'WebExtensionProviderSet',
] as $contract) {
    if (!str_contains($registry, $contract)) {
        throw new RuntimeException('Web extension registry contract is missing: ' . $contract);
    }
}

$set = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/WebExtensionProviderSet.php',
);
foreach ([
    'isEnabled($contribution->moduleId)',
    '$this->extensions->for($extensionPoint)',
    '$this->providers[$contribution->serviceId]',
    'must implement',
] as $contract) {
    if (!str_contains($set, $contract)) {
        throw new RuntimeException('Web provider set invariant is missing: ' . $contract);
    }
}

$contextCatalog = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/WebExtensionContextCatalog.php',
);
if (str_contains($contextCatalog, 'ActiveModuleResolver') || str_contains($contextCatalog, 'snapshot(')) {
    throw new RuntimeException('Context-bound extension catalog must not resolve a second module snapshot.');
}

$shellComposer = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php',
);

if (!str_contains($shellComposer, '$extensions = $this->extensions->forContext($context);')) {
    throw new RuntimeException('Provider-backed Shell must bind one extension context before composition.');
}

foreach (['NavigationBuilder', 'Domains\\', 'Doctrine\\'] as $forbidden) {
    if (str_contains($shellComposer, $forbidden)) {
        throw new RuntimeException('Provider-backed Shell leaked implementation/business dependency: ' . $forbidden);
    }
}

echo "Wave 12.5 Domain UI extension foundation passed.\n";
