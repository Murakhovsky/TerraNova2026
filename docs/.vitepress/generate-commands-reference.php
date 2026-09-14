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

    $definition['_root'] = dirname($modulePath);
    $modules[$definition['id']] = $definition;
}
ksort($modules, SORT_STRING);

$commands = [];
foreach ($modules as $moduleId => $module) {
    $paths = glob($module['_root'] . '/Application/DTO/*Command.php') ?: [];
    sort($paths, SORT_STRING);

    foreach ($paths as $path) {
        $commands[$moduleId][] = [
            'symbol' => pathinfo($path, PATHINFO_FILENAME),
            'source' => relativePath($repoRoot, $path),
        ];
    }
}

$outputPath = $repoRoot . '/docs/12-reference/commands.md';
$content = renderCommands($modules, $commands);

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

/**
 * @param array<string, array<string, mixed>> $modules
 * @param array<string, list<array{symbol: string, source: string}>> $commands
 */
function renderCommands(array $modules, array $commands): string
{
    $lines = [
        '---',
        'title: Command DTO Reference',
        'description: Generated index of explicit module-owned Application DTO command contracts.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Command DTO Reference',
        '',
        '> Джерело істини: `app/Domains/*/Application/DTO/*Command.php` для зареєстрованих module manifests.',
        '',
        'Цей catalogue документує тільки explicit command DTO contracts. Він навмисно не класифікує service methods, repositories або UseCase classes як Commands за назвою чи поведінкою.',
        '',
        '## Summary',
        '',
        '| Module | Commands |',
        '| --- | ---: |',
    ];

    foreach ($modules as $moduleId => $_module) {
        $lines[] = sprintf('| `%s` | %d |', table($moduleId), count($commands[$moduleId] ?? []));
    }

    foreach ($modules as $moduleId => $module) {
        $lines[] = '';
        $lines[] = sprintf('## %s (`%s`)', (string) ($module['name'] ?? $moduleId), $moduleId);
        $lines[] = '';

        $entries = $commands[$moduleId] ?? [];
        if ($entries === []) {
            $lines[] = 'Explicit `*Command` DTO contracts не знайдені.';
            continue;
        }

        $lines[] = '| Command | Source |';
        $lines[] = '| --- | --- |';
        foreach ($entries as $entry) {
            $lines[] = sprintf('| `%s` | `%s` |', table($entry['symbol']), table($entry['source']));
        }
    }

    $lines[] = '';
    $lines[] = '## Classification boundary';
    $lines[] = '';
    $lines[] = 'Назви на кшталт `ClientCaseCommandService` або `ClientCaseCommandRepositoryInterface` не потрапляють сюди: це services/contracts, а не command message DTO. Так само `Application/UseCase` документується окремим generated reference. Якщо COS пізніше введе typed Kernel Command contract, цей catalogue треба переключити на нього як на сильніший source of truth.';
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
