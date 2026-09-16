<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);
$vocabularyPath = $repoRoot . '/app/Infrastructure/Visualization/Architecture/ArchitectureGraphVocabulary.php';
require_once $vocabularyPath;

$reflection = new ReflectionClass('Infrastructure\\Visualization\\Architecture\\ArchitectureGraphVocabulary');
$types = [];
$relations = [];
foreach ($reflection->getConstants() as $name => $value) {
    if (!is_string($value)) continue;
    if (str_starts_with($name, 'TYPE_')) $types[$name] = $value;
    if (str_starts_with($name, 'REL_')) $relations[$name] = $value;
}
ksort($types);
ksort($relations);

$projectionPath = $repoRoot . '/app/Infrastructure/Visualization/Architecture/ArchitectureProjectionRegistry.php';
$projectionNames = [];
if (is_file($projectionPath)) {
    $source = (string)file_get_contents($projectionPath);
    if (preg_match_all("/new ArchitectureProjectionDefinition\\('([a-z][a-z0-9_]*)',\\s*'([^']+)'/", $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) $projectionNames[$match[1]] = $match[2];
    }
}

$lines = ['---','title: Довідник графа архітектури','description: Згенерований словник і каталог projections для канонічного COS Architecture Graph.','status: generated','kind: reference','generated: true','---','','<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->','','# Довідник графа архітектури','', '> Джерело істини: `ArchitectureGraphVocabulary` + `ArchitectureProjectionRegistry` у поточному checkout.','','## Типи вузлів','','| Константа | Значення |','| --- | --- |'];
foreach ($types as $name => $value) $lines[] = sprintf('| `%s` | `%s` |', $name, $value);
$lines[] = '';
$lines[] = '## Зв’язки';
$lines[] = '';
$lines[] = '| Константа | Значення |';
$lines[] = '| --- | --- |';
foreach ($relations as $name => $value) $lines[] = sprintf('| `%s` | `%s` |', $name, $value);
$lines[] = '';
$lines[] = '## Канонічні projections';
$lines[] = '';
$lines[] = '| Projection | Мітка |';
$lines[] = '| --- | --- |';
foreach ($projectionNames as $name => $label) $lines[] = sprintf('| `%s` | %s |', $name, $label);
if ($projectionNames === []) $lines[] = '| — | Статичних projection definitions не знайдено. |';
$lines[] = '';
$lines[] = '## Виконувана поверхня';
$lines[] = '';
$lines[] = 'Живий Architecture Explorer доступний у Web interface за `/cos/architecture`; ця сторінка документує vocabulary виконуваного графа, а не дублює його rendering.';
$lines[] = '';
$content = implode("\n", $lines);
$path = $repoRoot . '/docs/12-reference/architecture-graph.md';
if ($checkOnly) {
    $existing = is_file($path) ? file_get_contents($path) : false;
    if ($existing === false || str_replace("\r\n", "\n", $existing) !== str_replace("\r\n", "\n", $content)) {
        fwrite(STDERR, "Generated reference is stale: docs/12-reference/architecture-graph.md. Run npm run docs:generate.\n");
        exit(1);
    }
    exit(0);
}
file_put_contents($path, $content);
fwrite(STDOUT, "Generated docs/12-reference/architecture-graph.md\n");
