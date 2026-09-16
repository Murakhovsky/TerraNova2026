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
    $definition['_manifest'] = relativePath($repoRoot, $modulePath);
    $modules[$definition['id']] = $definition;
}
ksort($modules);

$outputs = [
    $repoRoot . '/docs/12-reference/configuration.md' => renderConfiguration($modules),
    $repoRoot . '/docs/12-reference/database.md' => renderDatabase($repoRoot, $modules),
    $repoRoot . '/docs/12-reference/errors-and-failures.md' => renderFailures($repoRoot),
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

function renderConfiguration(array $modules): string
{
    $lines = generatedHeader('Довідник конфігурації', 'Згенерована карта ownership для module configuration provisioners і declarations можливостей.');
    $lines[] = '> Джерело істини: `app/Domains/*/module.php` у поточному checkout.';
    $lines[] = '';
    $lines[] = '| Модуль | Версія | Configuration provisioners | Можливостей |';
    $lines[] = '| --- | --- | --- | ---: |';
    foreach ($modules as $id => $module) {
        $contributions = $module['contributions'] ?? [];
        $provisioners = stringList($contributions['configuration_provisioner_services'] ?? []);
        $capabilities = stringList($contributions['capabilities'] ?? []);
        $lines[] = sprintf('| `%s` | `%s` | %s | %d |', $id, (string)($module['version'] ?? 'unknown'), inlineList($provisioners), count($capabilities));
    }
    foreach ($modules as $id => $module) {
        $contributions = $module['contributions'] ?? [];
        $provisioners = stringList($contributions['configuration_provisioner_services'] ?? []);
        $capabilities = stringList($contributions['capabilities'] ?? []);
        $lines[] = '';
        $lines[] = "## `{$id}`";
        $lines[] = '';
        $lines[] = '- manifest: `' . $module['_manifest'] . '`;';
        $lines[] = '- configuration provisioners: ' . inlineList($provisioners) . ';';
        $lines[] = '- capabilities: ' . inlineList($capabilities) . '.';
    }
    $lines[] = '';
    return implode("\n", $lines);
}

function renderDatabase(string $repoRoot, array $modules): string
{
    $rows = [];
    foreach ($modules as $id => $module) {
        foreach (stringList(($module['contributions']['migration_files'] ?? [])) as $migration) {
            $path = $repoRoot . '/' . $migration;
            if (!is_file($path)) {
                fwrite(STDERR, "Manifest migration not found: {$migration}\n");
                exit(1);
            }
            $sql = stripSqlComments((string)file_get_contents($path));
            $tables = extractSqlTables($sql);
            if ($tables === []) {
                $rows[] = [$id, $migration, '—'];
                continue;
            }
            foreach ($tables as $table) $rows[] = [$id, $migration, $table];
        }
    }
    $lines = generatedHeader('Довідник бази даних', 'Згенерована карта module-owned міграцій і статично визначених звернень до SQL-таблиць.');
    $lines[] = '> Джерело істини: `migration_files` у module manifests + текст SQL-міграцій. SQL-коментарі відкидаються перед аналізом; dynamic SQL навмисно не вгадується.';
    $lines[] = '';
    $lines[] = '| Модуль | Міграція | Задіяні таблиці |';
    $lines[] = '| --- | --- | --- |';
    foreach ($rows as [$module, $migration, $table]) $lines[] = sprintf('| `%s` | `%s` | %s |', $module, $migration, $table === '—' ? '—' : '`' . $table . '`');
    $lines[] = '';
    return implode("\n", $lines);
}

function renderFailures(string $repoRoot): string
{
    require_once $repoRoot . '/app/Kernel/Execution/ExecutionFailureKind.php';
    $kindClass = 'Kernel\\Execution\\ExecutionFailureKind';
    $implementations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repoRoot . '/app', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $source = (string)file_get_contents($file->getPathname());
        if (str_contains($source, 'interface ClassifiedExecutionFailureInterface')) continue;
        if (preg_match('/(?:final\s+readonly\s+|final\s+|readonly\s+)?class\s+([A-Za-z0-9_]+)[^{]*(?:implements\s+[^\{]*ClassifiedExecutionFailureInterface|extends\s+ExecutionFailureException)/s', $source, $match)) {
            $implementations[$match[1]] = relativePath($repoRoot, $file->getPathname());
        }
    }
    ksort($implementations);
    $lines = generatedHeader('Довідник помилок і відмов', 'Згенерований каталог канонічних видів execution failure і явних classified failure implementations.');
    $lines[] = '> Джерело істини: `ExecutionFailureKind` + PHP-класи, які явно реалізують classified failure contract.';
    $lines[] = '';
    $lines[] = '## Види відмов';
    $lines[] = '';
    $lines[] = '| Вид | Можна повторити |';
    $lines[] = '| --- | --- |';
    foreach ($kindClass::cases() as $case) $lines[] = sprintf('| `%s` | %s |', $case->value, $case->retryable() ? 'так' : 'ні');
    $lines[] = '';
    $lines[] = '## Класифіковані реалізації';
    $lines[] = '';
    $lines[] = '| Символ | Джерело |';
    $lines[] = '| --- | --- |';
    foreach ($implementations as $symbol => $source) $lines[] = sprintf('| `%s` | `%s` |', $symbol, $source);
    if ($implementations === []) $lines[] = '| — | — |';
    $lines[] = '';
    return implode("\n", $lines);
}

function generatedHeader(string $title, string $description): array
{
    return ['---', "title: {$title}", "description: {$description}", 'status: generated', 'kind: reference', 'generated: true', '---', '', '<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->', '', "# {$title}", ''];
}

function stripSqlComments(string $sql): string
{
    $withoutBlocks = preg_replace('~/\*.*?\*/~s', ' ', $sql);
    $withoutBlocks = is_string($withoutBlocks) ? $withoutBlocks : $sql;
    $withoutLines = preg_replace('/--[^\r\n]*/', ' ', $withoutBlocks);
    return is_string($withoutLines) ? $withoutLines : $withoutBlocks;
}

function extractSqlTables(string $sql): array
{
    $tables = [];
    $patterns = [
        '/\b(?:CREATE|ALTER|DROP)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"]?([A-Za-z0-9_]+)[`"]?/i',
        '/\b(?:CREATE|DROP)\s+(?:UNIQUE\s+)?INDEX\b[^;]*?\bON\s+[`"]?([A-Za-z0-9_]+)[`"]?/i',
        '/\bREFERENCES\s+[`"]?([A-Za-z0-9_]+)[`"]?/i',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $sql, $matches)) continue;
        foreach ($matches[1] as $table) $tables[$table] = true;
    }
    $names = array_keys($tables);
    sort($names);
    return $names;
}

function stringList(mixed $value): array
{
    if (!is_array($value)) return [];
    return array_values(array_filter($value, 'is_string'));
}

function inlineList(array $items): string
{
    return $items === [] ? '—' : '`' . implode('`, `', $items) . '`';
}

function normalize(string $value): string { return str_replace("\r\n", "\n", $value); }
function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
}
