<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

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
$assert(!str_contains($provider, "rules('default')"), 'Visualization provider must not invent tenant/default rule scope.');
$assert(!str_contains($provider, "policies('default')"), 'Visualization provider must not invent tenant/default policy scope.');

$registry = $read('app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php');
foreach (["layout: 'hierarchical'", "layout: 'flow'", "'radial'", 'TYPE_RULE', 'TYPE_POLICY'] as $marker) {
    $assert(str_contains($registry, $marker), 'Projection hardening marker missing: ' . $marker);
}

$application = $read('symfony/src/Application/Visualization/Query/ArchitectureGraphQueryService.php');
foreach (['new GraphView(', '$focus', '$depth', '$canonical->hasNode('] as $marker) {
    $assert(str_contains($application, $marker), 'Server-side focused projection missing: ' . $marker);
}

$controller = $read('symfony/src/Web/Visualization/ArchitecturePageController.php');
foreach (["query->get('focus'", "query->get('depth'", 'GetArchitectureProjectionQuery'] as $marker) {
    $assert(str_contains($controller, $marker), 'Architecture endpoint input contract missing: ' . $marker);
}

$client = $read('symfony/assets/controllers/architecture_explorer_controller.js');
foreach (['loadProjection(', 'renderTypeFilters(', 'layoutOptions(', "hint === 'hierarchical'", "hint === 'flow'", "hint === 'radial'"] as $marker) {
    $assert(str_contains($client, $marker), 'Explorer browser hardening marker missing: ' . $marker);
}
$assert(!str_contains($client, 'const domainFocus'), 'Domain focus must remain server-projected.');

$view = $read('symfony/templates/experience/system/architecture.html.twig');
foreach ([
    'data-architecture-explorer-target="viewLabel"',
    'data-architecture-explorer-target="nodeCount"',
    'data-architecture-explorer-target="edgeCount"',
    'data-architecture-explorer-target="types"',
    'data-architecture-explorer-target="domain"',
    'data-architecture-explorer-target="depth"',
] as $marker) {
    $assert(str_contains($view, $marker), 'Explorer active-view marker missing: ' . $marker);
}

echo "Visualization V0.4.1/Wave 13 hardening passed.\n";
