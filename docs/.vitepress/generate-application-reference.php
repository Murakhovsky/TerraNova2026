<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);

$modulePaths = glob($repoRoot . '/app/Domains/*/module.php') ?: [];
sort($modulePaths, SORT_STRING);

$modules = [];
foreach ($modulePaths as $modulePath) {
    $definition = require $modulePath;
    if (!is_array($definition) || !isset($definition['id']) || !is_string($definition['id']) || $definition['id'] === '') {
        fwrite(STDERR, sprintf("Invalid module definition: %s\n", relativePath($repoRoot, $modulePath)));
        exit(1);
    }

    $definition['_source'] = relativePath($repoRoot, $modulePath);
    $definition['_root'] = dirname($modulePath);
    $modules[$definition['id']] = $definition;
}
ksort($modules, SORT_STRING);

$useCases = [];
foreach ($modules as $moduleId => $module) {
    $paths = glob($module['_root'] . '/Application/UseCase/*.php') ?: [];
    sort($paths, SORT_STRING);
    foreach ($paths as $path) {
        $useCases[$moduleId][] = [
            'symbol' => pathinfo($path, PATHINFO_FILENAME),
            'source' => relativePath($repoRoot, $path),
        ];
    }
}

$routeCatalogues = [
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
    $contributions = is_array($module['contributions'] ?? null) ? $module['contributions'] : [];
    $declaredServices = stringList($contributions['api_route_contributor_services'] ?? []);
    $catalogue = $routeCatalogues[$moduleId] ?? null;

    if ($declaredServices === []) {
        if ($catalogue !== null) {
            fwrite(STDERR, sprintf("Route catalogue exists for module without manifest contributor: %s\n", $moduleId));
            exit(1);
        }
        continue;
    }

    if (count($declaredServices) !== 1 || !is_array($catalogue) || ($catalogue['service'] ?? null) !== $declaredServices[0]) {
        fwrite(STDERR, sprintf("Route catalogue does not match manifest contributor for module: %s\n", $moduleId));
        exit(1);
    }

    foreach (array_merge([$catalogue['contributor']], $catalogue['sources']) as $source) {
        if (!is_string($source) || !is_file($repoRoot . '/' . $source)) {
            fwrite(STDERR, sprintf("Route source not found for module %s: %s\n", $moduleId, (string) $source));
            exit(1);
        }
    }
}

foreach ($routeCatalogues as $moduleId => $_catalogue) {
    if (!isset($modules[$moduleId])) {
        fwrite(STDERR, sprintf("Route catalogue references unregistered module: %s\n", $moduleId));
        exit(1);
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
        if ($existing === false || normalizeNewlines($existing) !== normalizeNewlines($content)) {
            fwrite(STDERR, sprintf(
                "Generated reference is stale: %s. Run npm run docs:generate.\n",
                relativePath($repoRoot, $path),
            ));
            $failed = true;
        }
        continue;
    }

    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, sprintf("Cannot write generated reference: %s\n", relativePath($repoRoot, $path)));
        $failed = true;
        continue;
    }
    fwrite(STDOUT, sprintf("Generated %s\n", relativePath($repoRoot, $path)));
}

exit($failed ? 1 : 0);

/** @return list<string> */
function stringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter($value, 'is_string'));
}

/**
 * @param array<string, array<string, mixed>> $modules
 * @param array<string, list<array{symbol: string, source: string}>> $useCases
 */
function renderUseCases(array $modules, array $useCases): string
{
    $lines = [
        '---',
        'title: Application Use Cases',
        'description: Generated index of module-owned Application/UseCase entry points.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Application Use Cases',
        '',
        '> Джерело істини: `app/Domains/*/Application/UseCase/*.php` для зареєстрованих module manifests.',
        '',
        'Цей індекс показує application entry points за чинною directory convention. Він не стверджує, що кожен клас є окремою Kernel Command і не намагається вгадувати семантику методів усередині service classes.',
        '',
        '## Summary',
        '',
        '| Module | Entry points |',
        '| --- | ---: |',
    ];

    foreach ($modules as $moduleId => $_module) {
        $lines[] = sprintf('| `%s` | %d |', table($moduleId), count($useCases[$moduleId] ?? []));
    }

    foreach ($modules as $moduleId => $module) {
        $lines[] = '';
        $lines[] = sprintf('## %s (`%s`)', (string) ($module['name'] ?? $moduleId), $moduleId);
        $lines[] = '';
        $entries = $useCases[$moduleId] ?? [];
        if ($entries === []) {
            $lines[] = 'Application UseCase entry points не знайдені.';
            continue;
        }

        $lines[] = '| Symbol | Source |';
        $lines[] = '| --- | --- |';
        foreach ($entries as $entry) {
            $lines[] = sprintf('| `%s` | `%s` |', table($entry['symbol']), table($entry['source']));
        }
    }

    $lines[] = '';
    $lines[] = '## Scope';
    $lines[] = '';
    $lines[] = 'Reference навмисно прив’язаний до явної `Application/UseCase` convention. Command services, handlers або operations, що живуть поза цією convention, мають отримати окремий explicit catalogue замість широкого regex-сканування PHP.';
    $lines[] = '';

    return implode("\n", $lines);
}

/**
 * @param array<string, array<string, mixed>> $modules
 * @param array<string, array{service: string, contributor: string, sources: list<string>}> $routeCatalogues
 */
function renderRoutes(array $modules, array $routeCatalogues): string
{
    $lines = [
        '---',
        'title: Module Routes',
        'description: Generated ownership map for module API route contributors and their route source files.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Module Routes',
        '',
        '> Джерела істини: `api_route_contributor_services` у module manifests та explicit route source registry у documentation generator.',
        '',
        'Маршрути є Web-layer contribution. Generator перевіряє, що explicit route catalogue збігається з manifest contributor і що всі зареєстровані source files існують.',
        '',
        '## Summary',
        '',
        '| Module | Manifest contributor | Route source files |',
        '| --- | --- | ---: |',
    ];

    foreach ($modules as $moduleId => $module) {
        $contributions = is_array($module['contributions'] ?? null) ? $module['contributions'] : [];
        $services = stringList($contributions['api_route_contributor_services'] ?? []);
        $catalogue = $routeCatalogues[$moduleId] ?? null;
        $lines[] = sprintf(
            '| `%s` | %s | %d |',
            table($moduleId),
            $services === [] ? '—' : implode(', ', array_map(static fn (string $service): string => sprintf('`%s`', table($service)), $services)),
            is_array($catalogue) ? count($catalogue['sources']) : 0,
        );
    }

    foreach ($routeCatalogues as $moduleId => $catalogue) {
        $lines[] = '';
        $lines[] = sprintf('## `%s`', $moduleId);
        $lines[] = '';
        $lines[] = sprintf('- manifest contributor service: `%s`;', $catalogue['service']);
        $lines[] = sprintf('- contributor implementation: `%s`;', $catalogue['contributor']);
        $lines[] = '- route sources:';
        foreach ($catalogue['sources'] as $source) {
            $lines[] = sprintf('  - `%s`;', $source);
        }
    }

    $lines[] = '';
    $lines[] = '## Scope';
    $lines[] = '';
    $lines[] = 'Цей шар документує ownership і source-of-truth для module routes без виконання Phalcon runtime та без парсингу довільного PHP. Endpoint-level table можна будувати окремим typed/structured route catalogue, коли route contract буде формалізований.';
    $lines[] = '';

    return implode("\n", $lines);
}

function table(string $value): string
{
    return str_replace('|', '\\|', $value);
}

function normalizeNewlines(string $value): string
{
    return str_replace("\r\n", "\n", $value);
}

function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
}
