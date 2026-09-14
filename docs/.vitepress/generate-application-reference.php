<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);

$modules = [];
foreach (glob($repoRoot . '/app/Domains/*/module.php') ?: [] as $modulePath) {
    $definition = require $modulePath;
    if (!is_array($definition) || !is_string($definition['id'] ?? null)) {
        fwrite(STDERR, "Invalid module definition: {$modulePath}\n");
        exit(1);
    }
    $definition['_root'] = dirname($modulePath);
    $modules[$definition['id']] = $definition;
}
ksort($modules);

$useCases = [];
foreach ($modules as $moduleId => $module) {
    foreach (glob($module['_root'] . '/Application/UseCase/*.php') ?: [] as $path) {
        $useCases[$moduleId][] = ['symbol' => pathinfo($path, PATHINFO_FILENAME), 'source' => relativePath($repoRoot, $path)];
    }
}

$routeCatalogues = [
    'diagnostic' => [
        'service' => 'diagnosticRouteContributor',
        'contributor' => 'app/Interfaces/Web/Routing/DiagnosticModuleRouteContributor.php',
        'sources' => ['app/Interfaces/Web/Routing/DiagnosticRoutes.php'],
    ],
    'property' => [
        'service' => 'propertyRouteContributor',
        'contributor' => 'app/Interfaces/Web/Routing/PropertyModuleRouteContributor.php',
        'sources' => ['app/Interfaces/Web/Routing/PropertyRuntimeRoutes.php'],
    ],
    'sales' => [
        'service' => 'salesRouteContributor',
        'contributor' => 'app/Interfaces/Web/Routing/SalesModuleRouteContributor.php',
        'sources' => [
            'app/Interfaces/Web/Routing/SalesAdministrationRoutes.php',
            'app/Interfaces/Web/Routing/SalesDirectorRoutes.php',
            'app/Interfaces/Web/Routing/SalesIntegrationRoutes.php',
            'app/Interfaces/Web/Routing/SalesRoutes.php',
            'app/Interfaces/Web/Routing/SalesTeamRoutes.php',
        ],
    ],
];

foreach ($modules as $moduleId => $module) {
    $services = array_values(array_filter(($module['contributions']['api_route_contributor_services'] ?? []), 'is_string'));
    $catalogue = $routeCatalogues[$moduleId] ?? null;
    if ($services === [] && $catalogue === null) continue;
    if (count($services) !== 1 || !is_array($catalogue) || $services[0] !== $catalogue['service']) {
        fwrite(STDERR, "Route catalogue does not match manifest contributor for module: {$moduleId}\n");
        exit(1);
    }
    foreach (array_merge([$catalogue['contributor']], $catalogue['sources']) as $source) {
        if (!is_file($repoRoot . '/' . $source)) {
            fwrite(STDERR, "Route source not found for module {$moduleId}: {$source}\n");
            exit(1);
        }
    }
}

$outputs = [
    $repoRoot . '/docs/12-reference/application-use-cases.md' => renderUseCases($modules, $useCases),
    $repoRoot . '/docs/12-reference/module-routes.md' => renderRoutes($modules, $routeCatalogues),
];

$failed = false;
foreach ($outputs as $path => $content) {
    if ($checkOnly) {
        $existing = is_file($path) ? file_get_contents($path) : false;
        if ($existing === false || normalize($existing) !== normalize($content)) {
            fwrite(STDERR, 'Generated reference is stale: ' . relativePath($repoRoot, $path) . ". Run npm run docs:generate.\n");
            $failed = true;
        }
    } else {
        file_put_contents($path, $content);
        fwrite(STDOUT, 'Generated ' . relativePath($repoRoot, $path) . "\n");
    }
}
exit($failed ? 1 : 0);

function renderUseCases(array $modules, array $useCases): string {
    $lines = ['---','title: Application Use Cases','description: Generated index of module-owned Application/UseCase entry points.','status: generated','kind: reference','generated: true','---','','<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->','','# Application Use Cases','', '> Джерело істини: `app/Domains/*/Application/UseCase/*.php` у current checkout.','','## Summary','','| Module | Entry points |','| --- | ---: |'];
    foreach ($modules as $id => $module) $lines[] = sprintf('| `%s` | %d |', $id, count($useCases[$id] ?? []));
    foreach ($modules as $id => $module) {
        $lines[] = ''; $lines[] = sprintf('## %s (`%s`)', $module['name'] ?? $id, $id); $lines[] = '';
        $entries = $useCases[$id] ?? [];
        if ($entries === []) { $lines[] = 'Application UseCase entry points не знайдені.'; continue; }
        $lines[] = '| Symbol | Source |'; $lines[] = '| --- | --- |';
        foreach ($entries as $entry) $lines[] = sprintf('| `%s` | `%s` |', $entry['symbol'], $entry['source']);
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function renderRoutes(array $modules, array $catalogues): string {
    $lines = ['---','title: Module Routes','description: Generated ownership map for module API route contributors and route source files.','status: generated','kind: reference','generated: true','---','','<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->','','# Module Routes','', '> Джерело істини: module manifests + explicit route source registry у current checkout.','','| Module | Contributor | Route source files |','| --- | --- | ---: |'];
    foreach ($modules as $id => $module) {
        $services = array_values(array_filter(($module['contributions']['api_route_contributor_services'] ?? []), 'is_string'));
        $catalogue = $catalogues[$id] ?? null;
        $lines[] = sprintf('| `%s` | %s | %d |', $id, $services === [] ? '—' : '`' . implode('`, `', $services) . '`', is_array($catalogue) ? count($catalogue['sources']) : 0);
    }
    foreach ($catalogues as $id => $catalogue) {
        if (!isset($modules[$id])) continue;
        $lines[] = ''; $lines[] = "## `{$id}`"; $lines[] = '';
        $lines[] = "- contributor: `{$catalogue['contributor']}`;";
        foreach ($catalogue['sources'] as $source) $lines[] = "- route source: `{$source}`;";
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function normalize(string $value): string { return str_replace("\r\n", "\n", $value); }
function relativePath(string $root, string $path): string {
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
}
