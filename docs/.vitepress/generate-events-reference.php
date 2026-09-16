<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);

$catalogues = [
    [
        'module' => 'property',
        'class' => \Domains\Property\Automation\Event\PropertyEventType::class,
        'method' => 'values',
        'source' => 'app/Domains/Property/Automation/Event/PropertyEventType.php',
        'owner_source' => 'app/Domains/Property/Bootstrap/PropertyDomainModule.php',
    ],
    [
        'module' => 'sales',
        'class' => \Domains\Sales\Automation\Event\SalesEventType::class,
        'method' => 'all',
        'source' => 'app/Domains/Sales/Automation/Event/SalesEventType.php',
        'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php',
    ],
];

$rows = [];
foreach ($catalogues as $catalogue) {
    require_once $repoRoot . '/' . $catalogue['source'];
    $class = $catalogue['class'];
    $method = $catalogue['method'];
    if (!class_exists($class) || !is_callable([$class, $method])) {
        fwrite(STDERR, "Invalid event catalogue: {$class}::{$method}()\n");
        exit(1);
    }
    $values = $class::$method();
    foreach ($values as $event) {
        if (!is_string($event) || $event === '') {
            fwrite(STDERR, "Invalid event type in {$class}\n");
            exit(1);
        }
        $rows[] = [
            'module' => $catalogue['module'],
            'event' => $event,
            'source' => $catalogue['source'],
            'owner' => $catalogue['owner_source'],
            'symbol' => $class . '::' . $method . '()',
        ];
    }
}
usort($rows, static fn(array $a, array $b): int => [$a['module'], $a['event']] <=> [$b['module'], $b['event']]);

$counts = [];
foreach ($rows as $row) $counts[$row['module']] = ($counts[$row['module']] ?? 0) + 1;
ksort($counts);

$lines = [
    '---',
    'title: Типи подій',
    'description: Згенерований довідник типів runtime-подій, якими володіють модулі.',
    'status: generated',
    'kind: reference',
    'generated: true',
    '---',
    '',
    '<!-- ЗГЕНЕРОВАНИЙ ФАЙЛ: НЕ РЕДАГУВАТИ ВРУЧНУ. Запустіть `npm run docs:generate`. -->',
    '',
    '# Типи подій',
    '',
    '> Джерело істини: явні каталоги подій у поточному checkout.',
    '',
    '## Підсумок',
    '',
    '| Модуль | Типів подій |',
    '| --- | ---: |',
];
foreach ($counts as $module => $count) $lines[] = "| `{$module}` | {$count} |";
$lines[] = '';
$lines[] = '## Каталог';
$lines[] = '';
$lines[] = '| Модуль | Тип події | Символ | Джерело | Runtime-власник |';
$lines[] = '| --- | --- | --- | --- | --- |';
foreach ($rows as $row) {
    $lines[] = sprintf('| `%s` | `%s` | `%s` | `%s` | `%s` |', $row['module'], $row['event'], $row['symbol'], $row['source'], $row['owner']);
}
$lines[] = '';
$content = implode("\n", $lines);
$output = $repoRoot . '/docs/12-reference/event-types.md';

if ($checkOnly) {
    $existing = is_file($output) ? file_get_contents($output) : false;
    if ($existing === false || str_replace("\r\n", "\n", $existing) !== str_replace("\r\n", "\n", $content)) {
        fwrite(STDERR, "Generated reference is stale: docs/12-reference/event-types.md. Run npm run docs:generate.\n");
        exit(1);
    }
    exit(0);
}

file_put_contents($output, $content);
fwrite(STDOUT, "Generated docs/12-reference/event-types.md\n");
