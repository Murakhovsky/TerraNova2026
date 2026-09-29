<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$providers = [
    'Sales' => ['SalesWebProvider', 'salesNavigationContributor'],
    'Property' => ['PropertyWebProvider', 'propertyNavigationContributor'],
    'Diagnostic' => ['DiagnosticWebProvider', 'diagnosticNavigationContributor'],
];

foreach ($providers as $domain => [$class, $serviceId]) {
    $manifest = (string) file_get_contents($root . '/app/Domains/' . $domain . '/module.php');
    if (!str_contains($manifest, "'web.search' => ['" . $serviceId . "']")) {
        throw new RuntimeException(sprintf('%s does not own its web.search contribution.', $domain));
    }

    $source = (string) file_get_contents(
        $root . '/symfony/src/Web/Experience/Extension/Provider/' . $class . '.php',
    );

    foreach ([
        'SearchProviderInterface',
        'SearchResultMatcher',
        'public function search(',
        "return '" . $serviceId . "';",
    ] as $contract) {
        if (!str_contains($source, $contract)) {
            throw new RuntimeException(sprintf('%s search contract is missing: %s', $class, $contract));
        }
    }

    foreach (['Doctrine\\', 'PDO', 'HttpClientInterface', '/api/v1/', 'fetch('] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf('%s search provider contains forbidden dependency: %s', $class, $forbidden));
        }
    }
}


$salesSearch = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/Provider/SalesWebProvider.php',
);
foreach ([
    'SalesEntitySearchInterface',
    '$this->entitySearch->search($context->organizationId',
    'new EntityRef($hit->entityType, $hit->entityId)',
    "kind: 'entity'",
] as $contract) {
    if (!str_contains($salesSearch, $contract)) {
        throw new RuntimeException('Sales entity search Web contract is missing: ' . $contract);
    }
}

$propertySearch = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/Provider/PropertyWebProvider.php',
);
foreach ([
    'PropertyEntitySearchInterface',
    '$this->entitySearch->search($context->organizationId',
    'new EntityRef($hit->entityType, $hit->entityId)',
    "kind: 'entity'",
] as $contract) {
    if (!str_contains($propertySearch, $contract)) {
        throw new RuntimeException('Property entity search Web contract is missing: ' . $contract);
    }
}

foreach ([$salesSearch, $propertySearch] as $entityProvider) {
    foreach (['Domains\\', 'SELECT ', 'INSERT ', 'UPDATE ', 'DELETE ', 'PDO', 'Doctrine\\', '/api/v1/'] as $forbidden) {
        if (str_contains($entityProvider, $forbidden)) {
            throw new RuntimeException('Entity search provider bypasses Application boundary: ' . $forbidden);
        }
    }
}

$salesAdapter = (string) file_get_contents(
    $root . '/symfony/src/Infrastructure/Experience/Search/SalesEntitySearchAdapter.php',
);
foreach ([
    'implements SalesEntitySearchInterface',
    'SalesWorkspaceReadModelInterface',
    '$this->sales->deals($organizationId',
    '$this->sales->leads($organizationId',
    "entityType: 'sales.deal'",
    "entityType: 'sales.lead'",
] as $contract) {
    if (!str_contains($salesAdapter, $contract)) {
        throw new RuntimeException('Sales entity search adapter is missing: ' . $contract);
    }
}

$propertyAdapter = (string) file_get_contents(
    $root . '/symfony/src/Infrastructure/Experience/Search/PropertyEntitySearchAdapter.php',
);
foreach ([
    'implements PropertyEntitySearchInterface',
    'PropertyReferencePort',
    '$this->properties->searchPropertyReferences($organizationId',
    '$this->properties->getPropertyPresentation($organizationId',
    "entityType: 'property.asset'",
] as $contract) {
    if (!str_contains($propertyAdapter, $contract)) {
        throw new RuntimeException('Property entity search adapter is missing: ' . $contract);
    }
}

$searchServices = (string) file_get_contents($root . '/symfony/config/services.yaml');
foreach ([
    'App\\Application\\Experience\\Search\\Contract\\SalesEntitySearchInterface:',
    'alias: App\\Infrastructure\\Experience\\Search\\SalesEntitySearchAdapter',
    'App\\Application\\Experience\\Search\\Contract\\PropertyEntitySearchInterface:',
    'alias: App\\Infrastructure\\Experience\\Search\\PropertyEntitySearchAdapter',
] as $contract) {
    if (!str_contains($searchServices, $contract)) {
        throw new RuntimeException('Entity search DI contract is missing: ' . $contract);
    }
}

$catalog = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/WebExtensionContextCatalog.php',
);
foreach (['public function search(', '$this->providers->search()', 'provider->search($this->context'] as $contract) {
    if (!str_contains($catalog, $contract)) {
        throw new RuntimeException('Context-bound search aggregation is missing: ' . $contract);
    }
}

$service = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Search/GlobalSearchService.php',
);
foreach ([
    '$this->extensions->forContext($context)',
    '$catalog->commands()',
    '$catalog->search($query',
    '$this->coreCommands->commands($context)',
    'deduplicate(',
] as $contract) {
    if (!str_contains($service, $contract)) {
        throw new RuntimeException('Global search service contract is missing: ' . $contract);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'PDO', 'HttpClientInterface', '/api/v1/'] as $forbidden) {
    if (str_contains($service, $forbidden)) {
        throw new RuntimeException('Global search service contains forbidden direct dependency: ' . $forbidden);
    }
}

$controller = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Search/GlobalSearchController.php',
);
foreach ([
    'TenantContextProviderInterface',
    'GlobalSearchService',
    "surface: 'workspace'",
    'organizationId: $tenant->organizationId()->value()',
    'role: $tenant->role()->value()',
    'no-store, private',
] as $contract) {
    if (!str_contains($controller, $contract)) {
        throw new RuntimeException('Global search controller contract is missing: ' . $contract);
    }
}
foreach (['Domains\\', 'Doctrine\\', 'PDO', 'HttpClientInterface'] as $forbidden) {
    if (str_contains($controller, $forbidden)) {
        throw new RuntimeException('Global search controller contains forbidden dependency: ' . $forbidden);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
if (!str_contains($routes, 'cos_web_global_search:')
    || !str_contains($routes, 'path: /workspace/search')
) {
    throw new RuntimeException('Canonical /workspace/search route is missing.');
}

$security = (string) file_get_contents($root . '/symfony/config/packages/security.yaml');
if (!str_contains($security, '|workspace|')
    || !str_contains($security, "path: '^/workspace(?:/|$)'")
    || !str_contains($security, 'roles: IS_AUTHENTICATED_FULLY')
) {
    throw new RuntimeException('Workspace search is not protected by authenticated Symfony security.');
}

$authenticator = (string) file_get_contents($root . '/symfony/src/Security/SessionAuthenticator.php');
if (!str_contains($authenticator, "str_starts_with(\$path,'/workspace')")) {
    throw new RuntimeException('Workspace search must use the canonical Web authentication entry point.');
}

$appJs = (string) file_get_contents($root . '/symfony/assets/app.js');
if (!str_contains($appJs, "import '@hotwired/turbo';")) {
    throw new RuntimeException('Turbo runtime is not explicitly started by the canonical app entrypoint.');
}

$shell = (string) file_get_contents($root . '/symfony/templates/experience/workspace_shell.html.twig');
foreach ([
    "path('cos_web_global_search')",
    'id="cos-global-search-results"',
    'data-workspace-shell-target="searchFrame"',
    'input->workspace-shell#searchPalette',
] as $contract) {
    if (!str_contains($shell, $contract)) {
        throw new RuntimeException('Workspace Shell global search contract is missing: ' . $contract);
    }
}

$stimulus = (string) file_get_contents($root . '/symfony/assets/controllers/workspace_shell_controller.js');
foreach ([
    'searchPalette()',
    'loadSearch(query)',
    "setAttribute('src'",
    '160',
    "event.key === 'ArrowDown'",
    "event.key === 'ArrowUp'",
    "event.key === 'Enter'",
] as $contract) {
    if (!str_contains($stimulus, $contract)) {
        throw new RuntimeException('Command palette behavior contract is missing: ' . $contract);
    }
}
foreach (['fetch(', 'axios', 'localStorage', 'sessionStorage', '/api/'] as $forbidden) {
    if (str_contains($stimulus, $forbidden)) {
        throw new RuntimeException('Command palette browser behavior contains forbidden transport/state access: ' . $forbidden);
    }
}

$coreCommands = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Shell/CoreCommandCatalog.php',
);
$shellComposer = (string) file_get_contents(
    $root . '/symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php',
);
if (!str_contains($shellComposer, '$this->coreCommands->commands($context)')
    || !str_contains($service, '$this->coreCommands->commands($context)')
) {
    throw new RuntimeException('Shell and Global Search must share CoreCommandCatalog.');
}

if (!str_contains($coreCommands, "'core.home'") || !str_contains($coreCommands, "'core.users'")) {
    throw new RuntimeException('Canonical core command catalog is incomplete.');
}

echo "Wave 12.15 Global Search and Command Palette passed.\n";
