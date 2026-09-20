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
    'property' => [
        'service' => null,
        'contributor' => null,
        'sources' => ['symfony/config/routes.yaml'],
    ],
];

foreach ($modules as $moduleId => $module) {
    $services = array_values(array_filter(($module['contributions']['api_route_contributor_services'] ?? []), 'is_string'));
    $catalogue = $routeCatalogues[$moduleId] ?? null;
    if ($services === [] && $catalogue === null) continue;
    if (!is_array($catalogue)) {
        fwrite(STDERR, "Route catalogue is missing for module: {$moduleId}\n");
        exit(1);
    }
    $expectedService = $catalogue['service'] ?? null;
    if (($expectedService === null && $services !== [])
        || ($expectedService !== null && $services !== [$expectedService])) {
        fwrite(STDERR, "Route catalogue does not match manifest contributor for module: {$moduleId}\n");
        exit(1);
    }
    $routeSources = $catalogue['sources'];
    if (is_string($catalogue['contributor'] ?? null) && $catalogue['contributor'] !== '') {
        array_unshift($routeSources, $catalogue['contributor']);
    }
    foreach ($routeSources as $source) {
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
    $lines = ['---','title: Сценарії використання Application','description: Згенерований індекс module-owned точок входу Application/UseCase.','status: generated','kind: reference','generated: true','---','','<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->','','# Сценарії використання Application','', '> Джерело істини: `app/Domains/*/Application/UseCase/*.php` у поточному checkout.','','## Підсумок','','| Модуль | Точок входу |','| --- | ---: |'];
    foreach ($modules as $id => $module) $lines[] = sprintf('| `%s` | %d |', $id, count($useCases[$id] ?? []));
    foreach ($modules as $id => $module) {
        $lines[] = ''; $lines[] = sprintf('## %s (`%s`)', $module['name'] ?? $id, $id); $lines[] = '';
        $entries = $useCases[$id] ?? [];
        if ($entries === []) { $lines[] = 'Точок входу Application UseCase не знайдено.'; continue; }
        $lines[] = '| Символ | Джерело |'; $lines[] = '| --- | --- |';
        foreach ($entries as $entry) $lines[] = sprintf('| `%s` | `%s` |', $entry['symbol'], $entry['source']);
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function renderRoutes(array $modules, array $catalogues): string {
    $lines = ['---','title: Маршрути модулів','description: Згенерована карта ownership для module API route contributors і файлів джерел маршрутів.','status: generated','kind: reference','generated: true','---','','<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->','','# Маршрути модулів','', '> Джерело істини: module manifests + явний registry джерел маршрутів у поточному checkout.','','| Модуль | Contributor | Файлів джерел маршрутів |','| --- | --- | ---: |'];
    foreach ($modules as $id => $module) {
        $services = array_values(array_filter(($module['contributions']['api_route_contributor_services'] ?? []), 'is_string'));
        $catalogue = $catalogues[$id] ?? null;
        $lines[] = sprintf('| `%s` | %s | %d |', $id, $services === [] ? '—' : '`' . implode('`, `', $services) . '`', is_array($catalogue) ? count($catalogue['sources']) : 0);
    }
    foreach ($catalogues as $id => $catalogue) {
        if (!isset($modules[$id])) continue;
        $lines[] = ''; $lines[] = "## `{$id}`"; $lines[] = '';
        $contributor = $catalogue['contributor'] ?? null;
        $lines[] = $contributor ? "- contributor: `{$contributor}`;" : "- ownership: Symfony route configuration;";
        foreach ($catalogue['sources'] as $source) $lines[] = "- джерело маршрутів: `{$source}`;";
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
