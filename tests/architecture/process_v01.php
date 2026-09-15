<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string)file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$required = [
    'app/Kernel/Process/ProcessDefinition.php',
    'app/Kernel/Process/ProcessStep.php',
    'app/Kernel/Process/ProcessEdge.php',
    'app/Kernel/Process/RuntimeMapping.php',
    'app/Kernel/Process/ProcessRegistryInterface.php',
    'app/Infrastructure/Process/JsonProcessRegistry.php',
    'app/Bootstrap/ProcessServices.php',
    'docs/.vitepress/process-registry.mjs',
];
foreach ($required as $path) $assert(is_file($root . '/' . $path), 'Process V0.1 required file missing: ' . $path);

$kernel = $read('app/Kernel/Process/ProcessRegistryInterface.php') . $read('app/Kernel/Process/ProcessDefinition.php') . $read('app/Kernel/Process/ProcessStep.php');
$assert(!str_contains($kernel, 'docs/.vitepress'), 'Kernel Process contracts must not depend on documentation tooling.');
$assert(!str_contains($kernel, 'Infrastructure\\'), 'Kernel Process contracts must not depend on Infrastructure.');
$assert(!str_contains($kernel, 'ModuleCatalog'), 'Kernel Process structural model must not verify module capability existence.');
$assert(!str_contains($kernel, 'Mermaid'), 'Kernel Process contracts must remain renderer-neutral.');
$assert(!str_contains($kernel, 'Cytoscape'), 'Kernel Process contracts must remain renderer-neutral.');
$assert(str_contains($kernel, 'SCHEMA_VERSION = 4'), 'Kernel Process model must preserve Process Registry schema v4.');
$assert(str_contains($kernel, 'capabilityGap'), 'Kernel Process model must preserve explicit capability debt.');

$bootstrap = $read('app/Bootstrap/ProcessServices.php');
$assert(str_contains($bootstrap, "'cosProcessRegistry'"), 'Process Registry runtime service is not registered.');
$assert(str_contains($bootstrap, "BASE_PATH . '/resources/processes'"), 'Runtime Process Registry must use neutral canonical source.');
$assert(str_contains($read('app/config/services_kernel.php'), "'/Bootstrap/ProcessServices.php'"), 'ProcessServices is not wired into composition root.');
$assert(str_contains($read('app/Kernel/Module/KernelVersion.php'), "VERSION = '0.11.9'"), 'KernelVersion must expose additive Process contract revision.');

$definitions = glob($root . '/resources/processes/*.json') ?: [];
$assert(count($definitions) === 3, 'Canonical Process Registry source must contain the three migrated definitions.');
foreach ($definitions as $path) {
    $definition = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $assert(($definition['schema_version'] ?? null) === 4, basename($path) . ' must preserve schema v4.');
    foreach ($definition['steps'] ?? [] as $step) {
        $assert(isset($step['domain']) && array_key_exists('capability', $step), basename($path) . ' step lost Domain/capability bridge.');
    }
}
$assert((glob($root . '/docs/.vitepress/processes/*.json') ?: []) === [], 'Documentation layer must not retain a second canonical Process Registry source.');

$adapter = $read('docs/.vitepress/process-registry.mjs');
$assert(str_contains($adapter, "'resources', 'processes'"), 'Documentation adapter must resolve canonical resources/processes.');
foreach ([
    'docs/.vitepress/check-processes.mjs',
    'docs/.vitepress/generate-business-process-reference.mjs',
    'docs/.vitepress/check-capability-debt.mjs',
    'docs/.vitepress/generate-capability-debt-reference.mjs',
    'docs/.vitepress/knowledge-health.mjs',
    'docs/.vitepress/domain-process-coverage.mjs',
] as $consumer) {
    $source = $read($consumer);
    $assert(!str_contains($source, "path.join(here, 'processes')"), $consumer . ' still owns the legacy docs Process Registry path.');
}

$diagram = $read('docs/.vitepress/theme/ProcessDiagram.vue');
$assert(str_contains($diagram, "../../../resources/processes/*.json"), 'ProcessDiagram must render from canonical Process Registry resources.');
$assert(str_contains($diagram, "'capability'"), 'Process V0.1 migration must preserve DOC V0.15 capability projection.');

echo "Process V0.1 architecture boundary passed.\n";
