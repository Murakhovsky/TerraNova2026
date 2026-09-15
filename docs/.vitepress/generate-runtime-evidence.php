<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);

$modulePaths = glob($repoRoot . '/app/Domains/*/module.php') ?: [];
sort($modulePaths, SORT_STRING);

$modules = [];
foreach ($modulePaths as $modulePath) {
    $definition = require $modulePath;
    if (!is_array($definition) || !is_string($definition['id'] ?? null) || trim($definition['id']) === '') {
        fwrite(STDERR, sprintf("Invalid module definition: %s\n", relativePath($repoRoot, $modulePath)));
        exit(1);
    }

    $definition['_root'] = dirname($modulePath);
    $definition['_source'] = relativePath($repoRoot, $modulePath);
    $modules[$definition['id']] = $definition;
}
ksort($modules, SORT_STRING);

$entries = [];

foreach ($modules as $moduleId => $module) {
    $useCases = glob($module['_root'] . '/Application/UseCase/*.php') ?: [];
    sort($useCases, SORT_STRING);
    foreach ($useCases as $path) {
        $entries[] = evidence(
            'use_case',
            pathinfo($path, PATHINFO_FILENAME),
            $moduleId,
            relativePath($repoRoot, $path),
            'source',
        );
    }

    $commands = glob($module['_root'] . '/Application/DTO/*Command.php') ?: [];
    sort($commands, SORT_STRING);
    foreach ($commands as $path) {
        $entries[] = evidence(
            'command',
            pathinfo($path, PATHINFO_FILENAME),
            $moduleId,
            relativePath($repoRoot, $path),
            'source',
        );
    }

    foreach (($module['contributions']['cross_domain_contracts'] ?? []) as $contract) {
        if (!is_array($contract)) {
            fwrite(STDERR, "Invalid cross-domain contract declaration in module {$moduleId}.\n");
            exit(1);
        }

        $ref = trim((string)($contract['contract'] ?? ''));
        $role = trim((string)($contract['role'] ?? ''));
        $counterpart = trim((string)($contract['counterpart'] ?? ''));
        if ($ref === '' || !in_array($role, ['requires', 'provides'], true) || $counterpart === '') {
            fwrite(STDERR, "Incomplete cross-domain contract declaration in module {$moduleId}.\n");
            exit(1);
        }

        $entry = evidence('contract', $ref, $moduleId, $module['_source'], 'runtime');
        $entry['role'] = $role;
        $entry['counterpart'] = $counterpart;
        $entry['kind'] = (string)($contract['kind'] ?? '');
        $entry['purpose'] = (string)($contract['purpose'] ?? '');
        $entries[] = $entry;
    }
}

$eventCatalogues = [
    [
        'domain' => 'property',
        'class' => 'Domains\\Property\\Automation\\Event\\PropertyEventType',
        'method' => 'values',
        'source' => 'app/Domains/Property/Automation/Event/PropertyEventType.php',
    ],
    [
        'domain' => 'sales',
        'class' => 'Domains\\Sales\\Automation\\Event\\SalesEventType',
        'method' => 'all',
        'source' => 'app/Domains/Sales/Automation/Event/SalesEventType.php',
    ],
];

foreach ($eventCatalogues as $catalogue) {
    $absolute = $repoRoot . '/' . $catalogue['source'];
    if (!is_file($absolute)) {
        fwrite(STDERR, "Runtime event catalogue source is missing: {$catalogue['source']}\n");
        exit(1);
    }

    require_once $absolute;
    $class = $catalogue['class'];
    $method = $catalogue['method'];
    if (!class_exists($class) || !is_callable([$class, $method])) {
        fwrite(STDERR, "Invalid runtime event catalogue: {$class}::{$method}()\n");
        exit(1);
    }

    foreach ($class::$method() as $eventType) {
        if (!is_string($eventType) || trim($eventType) === '') {
            fwrite(STDERR, "Invalid event type in {$class}::{$method}().\n");
            exit(1);
        }
        $entry = evidence('event', $eventType, $catalogue['domain'], $catalogue['source'], 'runtime');
        $entry['symbol'] = $class . '::' . $method . '()';
        $entries[] = $entry;
    }
}

usort($entries, static fn(array $a, array $b): int => [
    $a['type'],
    $a['domain'],
    $a['ref'],
    $a['source'],
] <=> [
    $b['type'],
    $b['domain'],
    $b['ref'],
    $b['source'],
]);

$seen = [];
foreach ($entries as $entry) {
    $key = implode('|', [$entry['type'], $entry['domain'], $entry['ref'], $entry['source']]);
    if (isset($seen[$key])) {
        fwrite(STDERR, "Duplicate runtime evidence entry: {$key}\n");
        exit(1);
    }
    $seen[$key] = true;
}

$payload = [
    'schema_version' => 1,
    'authority' => 'current_checkout',
    'verification_levels' => ['documented', 'source-verified', 'runtime-verified'],
    'entries' => $entries,
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "Failed to encode runtime evidence catalogue.\n");
    exit(1);
}

fwrite(STDOUT, $json . "\n");

/** @return array<string, mixed> */
function evidence(string $type, string $ref, string $domain, string $source, string $strength): array
{
    return [
        'type' => $type,
        'ref' => $ref,
        'domain' => $domain,
        'source' => $source,
        'strength' => $strength,
    ];
}

function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
}
