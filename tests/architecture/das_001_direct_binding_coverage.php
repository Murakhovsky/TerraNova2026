<?php
declare(strict_types=1);

/**
 * DAS-001 P0 source/DI binding regression.
 * This proves that every direct manifest query/command names exactly one
 * explicit App adapter compiled through the existing tagged Gateway DI.
 * It does NOT establish tenant activation, operator E2E, or D3 readiness.
 */
$root = dirname(__DIR__, 2);
$serviceConfiguration = file_get_contents($root . '/symfony/config/services.yaml');
if (!is_string($serviceConfiguration)
    || !str_contains($serviceConfiguration, 'App\\Application\\Capability\\Contract\\CapabilityInvocationAdapterInterface:')
    || !str_contains($serviceConfiguration, "name: 'cos.capability.invoker'")
    || !str_contains($serviceConfiguration, "resource: '../src/'")
    || !str_contains($serviceConfiguration, '!tagged_iterator cos.capability.invoker')) {
    throw new RuntimeException('Canonical Capability Gateway adapter DI registration is missing.');
}

$errorsFor = static function (array $contracts, array $adapters): array {
    $errors = [];
    foreach ($contracts as $id => $binding) {
        $implementations = $adapters[$binding] ?? [];
        if (count($implementations) !== 1) {
            $errors[] = $id . ': binding ' . $binding . ' has ' . count($implementations) . ' adapters, expected exactly one.';
        }
    }
    return $errors;
};

// The real matcher is checked against both missing and duplicate examples.
if ($errorsFor(['demo.read' => 'query:demo.read'], ['query:demo.read' => ['A']]) !== []
    || count($errorsFor(['demo.read' => 'query:demo.read'], [])) !== 1
    || count($errorsFor(['demo.read' => 'query:demo.read'], ['query:demo.read' => ['A', 'B']])) !== 1) {
    throw new RuntimeException('DAS-001 binding checker negative fixtures failed.');
}

$bindings = [];
$directories = [
    $root . '/symfony/src/Application/Capability',
    $root . '/symfony/src/Infrastructure/Capability',
];
foreach ($directories as $directory) {
    foreach (glob($directory . '/*Adapter.php') ?: [] as $filename) {
        $source = file_get_contents($filename);
        if (!is_string($source)
            || !preg_match('/implements\s+CapabilityInvocationAdapterInterface\b/', $source)) {
            continue;
        }
        if (!preg_match('/function\s+binding\s*\(\s*\)\s*:\s*string\s*\{\s*return\s*[\'"]([^\'"]+)[\'"]\s*;/s', $source, $match)) {
            throw new RuntimeException('Cannot inspect explicit capability binding in ' . $filename);
        }
        if (!preg_match('/^(?:query|command):[a-z][a-z0-9_.]+$/D', $match[1])) {
            throw new RuntimeException('Invalid direct capability binding in ' . $filename);
        }
        $bindings[$match[1]][] = substr($filename, strlen($root) + 1);
    }
}

$direct = [];
$domainCounts = [];
foreach (glob($root . '/app/Domains/*/module.php') ?: [] as $file) {
    $module = require $file;
    if (!is_array($module) || !is_string($module['id'] ?? null)) {
        throw new RuntimeException('Invalid Domain module manifest: ' . $file);
    }
    foreach ($module['contributions']['capability_contracts'] ?? [] as $contract) {
        $binding = $contract['execution_binding'] ?? '';
        if (!str_starts_with($binding, 'query:') && !str_starts_with($binding, 'command:')) {
            continue; // Action and read: bindings belong to different runtime authorities.
        }
        $id = $contract['id'] ?? '';
        if (!is_string($id) || !str_starts_with($id, $module['id'] . '.')) {
            throw new RuntimeException('Invalid direct binding ownership in ' . $file);
        }
        if (isset($direct[$id])) {
            throw new RuntimeException('Duplicate direct capability ID: ' . $id);
        }
        $direct[$id] = $binding;
        $domainCounts[$module['id']] = ($domainCounts[$module['id']] ?? 0) + 1;
    }
}

$issues = $errorsFor($direct, $bindings);
if ($issues !== []) {
    throw new RuntimeException("DAS-001 source binding gaps:\n- " . implode("\n- ", $issues));
}
ksort($domainCounts);
echo 'DAS-001 direct binding coverage PASS: ' . count($direct)
    . ' declared direct contracts, exactly one source adapter each, across '
    . count($domainCounts) . " Domains. Runtime invocation still requires tenant/DI checks.\n";
