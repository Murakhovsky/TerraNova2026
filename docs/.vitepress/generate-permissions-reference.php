<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);

$catalogues = [
    [
        'module' => 'sales',
        'class' => \Domains\Sales\Model\SalesCapability::class,
        'source' => 'app/Domains/Sales/Model/SalesCapability.php',
        'requires' => [
            'app/Domains/Sales/Model/HasStringValues.php',
            'app/Domains/Sales/Model/SalesCapability.php',
        ],
    ],
];

$manifestCapabilities = [];
$modulePaths = glob($repoRoot . '/app/Domains/*/module.php') ?: [];
sort($modulePaths, SORT_STRING);

foreach ($modulePaths as $modulePath) {
    $definition = require $modulePath;
    if (!is_array($definition) || !isset($definition['id']) || !is_string($definition['id'])) {
        fwrite(STDERR, sprintf("Invalid module definition: %s\n", relativePath($repoRoot, $modulePath)));
        exit(1);
    }

    $contributions = is_array($definition['contributions'] ?? null) ? $definition['contributions'] : [];
    foreach (stringList($contributions['capabilities'] ?? []) as $capability) {
        $manifestCapabilities[$definition['id']][$capability] = [
            'source' => relativePath($repoRoot, $modulePath),
        ];
    }
}

$runtimeCapabilities = [];
foreach ($catalogues as $catalogue) {
    foreach ($catalogue['requires'] as $requiredFile) {
        require_once $repoRoot . '/' . $requiredFile;
    }

    $class = $catalogue['class'];
    if (!enum_exists($class)) {
        fwrite(STDERR, sprintf("Capability catalogue enum not found: %s\n", $class));
        exit(1);
    }

    foreach ($class::cases() as $case) {
        if (!$case instanceof \BackedEnum || !is_string($case->value)) {
            fwrite(STDERR, sprintf("Capability catalogue must be string-backed: %s\n", $class));
            exit(1);
        }
        $runtimeCapabilities[$catalogue['module']][$case->value] = [
            'source' => $catalogue['source'],
            'symbol' => $class,
        ];
    }
}

$moduleIds = array_values(array_unique(array_merge(
    array_keys($manifestCapabilities),
    array_keys($runtimeCapabilities),
)));
sort($moduleIds, SORT_STRING);

$rows = [];
foreach ($moduleIds as $moduleId) {
    $capabilityIds = array_values(array_unique(array_merge(
        array_keys($manifestCapabilities[$moduleId] ?? []),
        array_keys($runtimeCapabilities[$moduleId] ?? []),
    )));
    sort($capabilityIds, SORT_STRING);

    foreach ($capabilityIds as $capabilityId) {
        $runtime = isset($runtimeCapabilities[$moduleId][$capabilityId]);
        $manifest = isset($manifestCapabilities[$moduleId][$capabilityId]);
        $rows[] = [
            'module' => $moduleId,
            'capability' => $capabilityId,
            'runtime' => $runtime,
            'manifest' => $manifest,
            'classification' => $runtime && $manifest ? 'both' : ($runtime ? 'runtime-only' : 'manifest-only'),
            'runtime_source' => $runtimeCapabilities[$moduleId][$capabilityId]['source'] ?? null,
            'manifest_source' => $manifestCapabilities[$moduleId][$capabilityId]['source'] ?? null,
        ];
    }
}

$outputPath = $repoRoot . '/docs/12-reference/permissions-capabilities.md';
$content = renderPermissions($rows, $catalogues);

if ($checkOnly) {
    $existing = is_file($outputPath) ? file_get_contents($outputPath) : false;
    if ($existing === false || normalizeNewlines($existing) !== normalizeNewlines($content)) {
        fwrite(STDERR, sprintf(
            "Generated reference is stale: %s. Run npm run docs:generate.\n",
            relativePath($repoRoot, $outputPath),
        ));
        exit(1);
    }

    exit(0);
}

if (file_put_contents($outputPath, $content) === false) {
    fwrite(STDERR, sprintf("Cannot write generated reference: %s\n", relativePath($repoRoot, $outputPath)));
    exit(1);
}

fwrite(STDOUT, sprintf("Generated %s\n", relativePath($repoRoot, $outputPath)));

/** @return list<string> */
function stringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter($value, 'is_string'));
}

/**
 * @param list<array{module: string, capability: string, runtime: bool, manifest: bool, classification: string, runtime_source: ?string, manifest_source: ?string}> $rows
 * @param list<array{module: string, class: class-string, source: string, requires: list<string>}> $catalogues
 */
function renderPermissions(array $rows, array $catalogues): string
{
    $both = count(array_filter($rows, static fn (array $row): bool => $row['classification'] === 'both'));
    $runtimeOnly = count(array_filter($rows, static fn (array $row): bool => $row['classification'] === 'runtime-only'));
    $manifestOnly = count(array_filter($rows, static fn (array $row): bool => $row['classification'] === 'manifest-only'));

    $lines = [
        '---',
        'title: Permissions and Capabilities',
        'description: Generated comparison of runtime capability vocabularies and module manifest declarations.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Permissions and Capabilities',
        '',
        '> Джерела істини: explicit runtime capability catalogues та `capabilities` у `app/Domains/*/module.php`.',
        '',
        'Ця сторінка навмисно **не виправляє** розбіжності між runtime vocabulary і module manifest. Вона робить drift видимим, щоб архітектурне рішення залишалося явним.',
        '',
        '## Summary',
        '',
        '| Classification | Count | Meaning |',
        '| --- | ---: | --- |',
        sprintf('| `both` | %d | Capability присутня і в runtime catalogue, і в manifest. |', $both),
        sprintf('| `runtime-only` | %d | Runtime може перевіряти capability, але manifest її не декларує. |', $runtimeOnly),
        sprintf('| `manifest-only` | %d | Manifest декларує capability, але explicit runtime catalogue її не містить. |', $manifestOnly),
        '',
        '## Catalogue',
        '',
        '| Module | Capability | Runtime | Manifest | Classification | Runtime source | Manifest source |',
        '| --- | --- | --- | --- | --- | --- | --- |',
    ];

    foreach ($rows as $row) {
        $lines[] = sprintf(
            '| `%s` | `%s` | %s | %s | `%s` | %s | %s |',
            table($row['module']),
            table($row['capability']),
            $row['runtime'] ? 'yes' : 'no',
            $row['manifest'] ? 'yes' : 'no',
            table($row['classification']),
            sourceCell($row['runtime_source']),
            sourceCell($row['manifest_source']),
        );
    }

    $lines[] = '';
    $lines[] = '## Runtime catalogue registry';
    $lines[] = '';
    $lines[] = 'Runtime vocabularies підключаються до генератора **явно**, а не через regex-сканування PHP. Це робить джерело authority передбачуваним і не змушує documentation tooling вгадувати семантику довільних класів.';
    $lines[] = '';
    $lines[] = '| Module | Symbol | Source |';
    $lines[] = '| --- | --- | --- |';
    foreach ($catalogues as $catalogue) {
        $lines[] = sprintf(
            '| `%s` | `%s` | `%s` |',
            table($catalogue['module']),
            table($catalogue['class']),
            table($catalogue['source']),
        );
    }

    $lines[] = '';
    $lines[] = '## Interpretation';
    $lines[] = '';
    $lines[] = '- `runtime-only` не означає автоматично помилку: capability може бути внутрішньою authorization vocabulary і свідомо не входити до exposed module surface.';
    $lines[] = '- `manifest-only` також не виправляється генератором: це сигнал перевірити, чи існує runtime authority для задекларованого permission.';
    $lines[] = '- Зміни до будь-якого з двох джерел мають змінити цей generated файл; `npm run docs:generate:check` ловить stale reference у CI.';
    $lines[] = '';

    return implode("\n", $lines);
}

function sourceCell(?string $source): string
{
    return $source === null ? '—' : sprintf('`%s`', table($source));
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
