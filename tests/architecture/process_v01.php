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
    'docs/.vitepress/process-registry.mjs',
];
foreach ($required as $path) $assert(is_file($root . '/' . $path), 'Process V0.1 required file missing: ' . $path);

$kernel = $read('app/Kernel/Process/ProcessRegistryInterface.php')
    . $read('app/Kernel/Process/ProcessDefinition.php')
    . $read('app/Kernel/Process/ProcessStep.php');
$assert(!str_contains($kernel, 'docs/.vitepress'), 'Kernel Process contracts must not depend on documentation tooling.');
$assert(!str_contains($kernel, 'Infrastructure\\'), 'Kernel Process contracts must not depend on Infrastructure.');
$assert(!str_contains($kernel, 'ModuleCatalog'), 'Kernel Process structural model must not verify module capability existence.');
$assert(str_contains($kernel, 'SCHEMA_VERSIONS = [4, 5]'), 'Kernel Process model must preserve schema v4/v5 compatibility.');
$assert(str_contains($kernel, 'CROSS_DOMAIN_SCHEMA = 5'), 'Kernel Process model must guard cross-domain structure at schema v5.');
$assert(str_contains($kernel, 'capabilityGap'), 'Kernel Process model must preserve explicit capability debt.');
$assert(str_contains($kernel, "mapping->type === 'contract'"), 'Kernel Process v5 cross-domain steps must require a structural contract mapping.');

$composition = $read('symfony/config/services.yaml');
$assert(str_contains($composition, 'Infrastructure\\Process\\JsonProcessRegistry:'), 'Process Registry runtime service is not registered in Symfony.');
$assert(str_contains($composition, "%kernel.project_dir%/../resources/processes"), 'Runtime Process Registry must use neutral canonical source.');
$assert(str_contains($composition, 'Kernel\\Process\\ProcessRegistryInterface:'), 'Process Registry contract alias is missing.');
$assert(!is_file($root.'/app/Bootstrap/ProcessServices.php'), 'Retired Process bootstrap must remain deleted.');
$assert(str_contains($read('app/Kernel/Module/KernelVersion.php'), "VERSION = '0.11.9'"), 'KernelVersion must expose additive Process contract revision.');

$definitions = glob($root . '/resources/processes/*.json') ?: [];
$assert(count($definitions) === 7, 'Canonical Process Registry source must contain seven current definitions.');
$schemas = [];
foreach ($definitions as $path) {
    $definition = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $schema = (int)($definition['schema_version'] ?? 0);
    $assert(in_array($schema, [4, 5], true), basename($path) . ' must use supported Process schema v4/v5.');
    $schemas[$schema] = true;
    foreach ($definition['steps'] ?? [] as $step) {
        $assert(isset($step['domain']) && array_key_exists('capability', $step), basename($path) . ' step lost Domain/capability bridge.');
    }
}
$assert(isset($schemas[4], $schemas[5]), 'Canonical Process Registry must exercise both v4 compatibility and v5 cross-domain semantics.');
$assert((glob($root . '/docs/.vitepress/processes/*.json') ?: []) === [], 'Documentation layer must not retain a second canonical Process Registry source.');

$crossDomain = json_decode((string)file_get_contents($root . '/resources/processes/sales-request-to-property-match.json'), true, flags: JSON_THROW_ON_ERROR);
$assert(($crossDomain['schema_version'] ?? null) === 5, 'Sales Request → Property Match must use schema v5.');
$resolveProperty = null;
foreach ($crossDomain['steps'] ?? [] as $step) {
    if (($step['id'] ?? null) === 'resolve-property') $resolveProperty = $step;
}
$assert(is_array($resolveProperty), 'Cross-domain Property resolution step is missing.');
$assert(($resolveProperty['domain'] ?? null) === 'property', 'Property resolution step must be owned by Property Domain.');
$assert(($resolveProperty['capability'] ?? null) === 'property.reference', 'Cross-domain Property step must use property.reference capability.');
$contracts = array_values(array_filter($resolveProperty['runtime'] ?? [], static fn (array $mapping): bool => ($mapping['type'] ?? null) === 'contract'));
$assert(count($contracts) === 1, 'Cross-domain Property step must declare one contract mapping.');
$assert(($contracts[0]['ref'] ?? null) === 'Domains\\Property\\Contract\\PropertyReferencePort', 'Cross-domain Property step must use PropertyReferencePort.');

$realEstate = json_decode((string)file_get_contents($root . '/resources/processes/real-estate-opportunity-to-reservation.json'), true, flags: JSON_THROW_ON_ERROR);
$assert(($realEstate['schema_version'] ?? null) === 5, 'RealEstate Opportunity → Reservation must use schema v5.');
$assert(($realEstate['domain'] ?? null) === 'real_estate', 'RealEstate process must be owned by real_estate.');
$crossDomains = array_values(array_filter(
    $realEstate['steps'] ?? [],
    static fn (array $step): bool => ($step['domain'] ?? 'real_estate') !== 'real_estate',
));
$assert(count($crossDomains) === 3, 'RealEstate process must expose Sales validation plus two Property boundary steps.');
foreach ($crossDomains as $step) {
    $contracts = array_values(array_filter(
        $step['runtime'] ?? [],
        static fn (array $mapping): bool => ($mapping['type'] ?? null) === 'contract',
    ));
    $assert(count($contracts) >= 1, 'Each RealEstate cross-domain process step requires a contract mapping.');
}

$service = json_decode((string)file_get_contents($root . '/resources/processes/service-request-to-close.json'), true, flags: JSON_THROW_ON_ERROR);
$assert(($service['schema_version'] ?? null) === 4, 'Service Request → Close must use schema v4.');
$assert(($service['domain'] ?? null) === 'service', 'Service process must be owned by service.');
$serviceCapabilities = array_values(array_filter(
    array_map(static fn (array $step): mixed => $step['capability'] ?? null, $service['steps'] ?? []),
    static fn (mixed $capability): bool => is_string($capability) && $capability !== '',
));
$assert(count($serviceCapabilities) === count($service['steps'] ?? []), 'Every Service process step must map to a Service capability.');

$growth = json_decode((string)file_get_contents($root . '/resources/processes/growth-opportunity-candidate-to-handoff.json'), true, flags: JSON_THROW_ON_ERROR);
$assert(($growth['schema_version'] ?? null) === 4, 'Growth Opportunity Candidate → Handoff must use schema v4 until a real cross-domain acceptance step exists.');
$assert(($growth['domain'] ?? null) === 'growth', 'Growth process must be owned by growth.');
$assert(($growth['state'] ?? null) === 'to-be', 'Growth V0.1 process must remain explicitly to-be until persistence and delivery surfaces are implemented.');
$assert(count($growth['steps'] ?? []) === 6, 'Growth V0.1 process must preserve its six-step opportunity intelligence flow.');
foreach ($growth['steps'] ?? [] as $step) {
    $capability = $step['capability'] ?? null;
    $assert(is_string($capability) && str_starts_with($capability, 'growth.'), 'Every Growth V0.1 process step must map to a Growth capability.');
}
$assert(($growth['steps'][5]['capability'] ?? null) === 'growth.handoff.prepare', 'Growth process must stop at a Growth-owned handoff package.');

$consumers = [
    'docs/.vitepress/check-processes.mjs',
    'docs/.vitepress/check-capability-debt.mjs',
    'docs/.vitepress/knowledge-health.mjs',
    'docs/.vitepress/domain-process-coverage.mjs',
    'docs/.vitepress/generate-business-process-reference.mjs',
    'docs/.vitepress/generate-capability-debt-reference.mjs',
    'docs/.vitepress/cross-domain-process-topology.mjs',
];
foreach ($consumers as $path) {
    $source = $read($path);
    $assert(!str_contains($source, "path.join(here, 'processes')"), $path . ' must not own a private Process Registry path.');
}
$diagram = $read('docs/.vitepress/theme/ProcessDiagram.vue');
$assert(str_contains($diagram, "../../../resources/processes/*.json"), 'ProcessDiagram must render from platform Process Registry resources.');
$assert(str_contains($diagram, "'domain'"), 'ProcessDiagram must preserve schema-v5 Domain projection.');

echo "Process V0.1 architecture boundary passed.\n";
