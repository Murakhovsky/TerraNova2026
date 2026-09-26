<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$required = [
    'app/Kernel/Module/Contract/BootstrapRuleProvidingModuleInterface.php',
    'app/Kernel/Module/Contract/BootstrapPolicyProvidingModuleInterface.php',
    'app/Infrastructure/Visualization/Architecture/ArchitectureGraphProvider.php',
    'app/Infrastructure/Visualization/Architecture/ArchitectureProjectionDefinition.php',
    'symfony/src/Web/Visualization/ArchitecturePageController.php',
    'tests/unit/visualization_architecture_graph.php',
    'tests/unit/visualization_architecture_projections.php',
    'docs/03-architecture/visualization-v0.4.1.md',
];
foreach ($required as $path) {
    $assert(is_file($root . '/' . $path), 'Missing Visualization V0.4.1 file: ' . $path);
}

$vocabulary = $read('app/Infrastructure/Visualization/Architecture/ArchitectureGraphVocabulary.php');
foreach (['TYPE_RULE', 'TYPE_POLICY', 'REL_TRIGGERS', 'REL_PRODUCES', 'REL_GOVERNS'] as $symbol) {
    $assert(str_contains($vocabulary, $symbol), 'Architecture vocabulary missing: ' . $symbol);
}

$provider = $read('app/Infrastructure/Visualization/Architecture/ArchitectureGraphProvider.php');
foreach ([
    'BootstrapRuleProvidingModuleInterface',
    'BootstrapPolicyProvidingModuleInterface',
    'appendBootstrapRules',
    'appendBootstrapPolicies',
    "'bootstrap_default'",
    "'manifest.kernel_constraint'",
    "'runtime_contract'",
] as $marker) {
    $assert(str_contains($provider, $marker), 'Architecture provider hardening marker missing: ' . $marker);
}
$assert(!str_contains($provider, "rules('default')"), 'Visualization provider must not invent tenant/default rule scope itself.');
$assert(!str_contains($provider, "policies('default')"), 'Visualization provider must not invent tenant/default policy scope itself.');

$salesModule = $read('app/Domains/Sales/Bootstrap/SalesDomainModule.php');
foreach (['BootstrapRuleProvidingModuleInterface', 'BootstrapPolicyProvidingModuleInterface', 'bootstrapRules()', 'bootstrapPolicies()'] as $marker) {
    $assert(str_contains($salesModule, $marker), 'Sales runtime module does not expose bootstrap automation architecture: ' . $marker);
}

$registry = $read('app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php');
foreach (["layout: 'hierarchical'", "layout: 'flow'", "'radial'", 'TYPE_RULE', 'TYPE_POLICY'] as $marker) {
    $assert(str_contains($registry, $marker), 'Projection hardening marker missing: ' . $marker);
}

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach (['public function graph(', "query->get('focus'", "query->get('depth'", 'new GraphView(', "\$payload['view'] = ["] as $marker) {
    $assert(str_contains($controller, $marker), 'Server-side Architecture Graph projection marker missing: ' . $marker);
}
$assert(!str_contains($controller, 'Infrastructure\\'), 'Web Architecture controller must remain free of Infrastructure compile-time dependencies.');

$routes = $read('symfony/config/routes.yaml');
$assert(str_contains($routes, 'cos_web_architecture_graph:'), 'Server-side Architecture Graph endpoint route missing.');

$client = $read('frontend/features/cos/architecture-explorer.js');
foreach (['fetchDomainProjection', 'data-architecture-node-count', 'renderTypeFilters', 'layoutOptions', "hint === 'hierarchical'", "hint === 'flow'", "hint === 'radial'"] as $marker) {
    $assert(str_contains($client, $marker), 'Explorer browser hardening marker missing: ' . $marker);
}
$assert(!str_contains($client, 'const domainFocus'), 'Domain focus must no longer be computed from the full graph in browser code.');

$view = $read('symfony/templates/experience/visualization/architecture.html.twig');
foreach (['data-architecture-endpoint', 'data-architecture-view-label', 'data-architecture-node-count', 'data-architecture-edge-count', 'data-architecture-types'] as $marker) {
    $assert(str_contains($view, $marker), 'Explorer active-view UI marker missing: ' . $marker);
}

echo "Visualization V0.4.1 hardening architecture passed.\n";
