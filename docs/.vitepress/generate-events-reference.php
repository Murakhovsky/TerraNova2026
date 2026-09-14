<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);

$catalogues = [
    [
        'module' => 'sales',
        'class' => \Domains\Sales\Automation\Event\SalesEventType::class,
        'method' => 'all',
        'source' => 'app/Domains/Sales/Automation/Event/SalesEventType.php',
        'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php',
    ],
];

$eventClasses = [
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\ActionOutcomeMeasured::class, 'source' => 'app/Domains/Sales/Automation/Event/ActionOutcomeMeasured.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\CallCompleted::class, 'source' => 'app/Domains/Sales/Automation/Event/CallCompleted.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\ClientCaseChanged::class, 'source' => 'app/Domains/Sales/Automation/Event/ClientCaseChanged.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\ClientCaseCreated::class, 'source' => 'app/Domains/Sales/Automation/Event/ClientCaseCreated.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\DealCreated::class, 'source' => 'app/Domains/Sales/Automation/Event/DealCreated.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\DealStageChanged::class, 'source' => 'app/Domains/Sales/Automation/Event/DealStageChanged.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\FollowupOverdue::class, 'source' => 'app/Domains/Sales/Automation/Event/FollowupOverdue.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\LeadChanged::class, 'source' => 'app/Domains/Sales/Automation/Event/LeadChanged.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
    ['module' => 'sales', 'class' => \Domains\Sales\Automation\Event\LeadCreated::class, 'source' => 'app/Domains/Sales/Automation/Event/LeadCreated.php', 'owner_source' => 'app/Domains/Sales/Bootstrap/SalesDomainModule.php'],
];

$events = [];

foreach ($catalogues as $catalogue) {
    require_once $repoRoot . '/' . $catalogue['source'];

    $class = $catalogue['class'];
    $method = $catalogue['method'];
    if (!class_exists($class) || !is_callable([$class, $method])) {
        fwrite(STDERR, sprintf("Event catalogue is not callable: %s::%s()\n", $class, $method));
        exit(1);
    }

    $values = call_user_func([$class, $method]);
    if (!is_array($values)) {
        fwrite(STDERR, sprintf("Event catalogue must return an array: %s::%s()\n", $class, $method));
        exit(1);
    }

    foreach ($values as $eventType) {
        if (!is_string($eventType) || $eventType === '') {
            fwrite(STDERR, sprintf("Event catalogue returned an invalid type: %s::%s()\n", $class, $method));
            exit(1);
        }

        addEvent($events, [
            'module' => $catalogue['module'],
            'event' => $eventType,
            'declaration' => 'catalogue',
            'symbol' => $class . '::' . $method . '()',
            'source' => $catalogue['source'],
            'owner_source' => $catalogue['owner_source'],
        ]);
    }
}

foreach ($eventClasses as $eventClass) {
    require_once $repoRoot . '/' . $eventClass['source'];

    $class = $eventClass['class'];
    if (!class_exists($class)) {
        fwrite(STDERR, sprintf("Event class not found: %s\n", $class));
        exit(1);
    }

    $constant = $class . '::TYPE';
    if (!defined($constant)) {
        fwrite(STDERR, sprintf("Event class does not declare TYPE: %s\n", $class));
        exit(1);
    }

    $eventType = constant($constant);
    if (!is_string($eventType) || $eventType === '') {
        fwrite(STDERR, sprintf("Event TYPE must be a non-empty string: %s\n", $constant));
        exit(1);
    }

    addEvent($events, [
        'module' => $eventClass['module'],
        'event' => $eventType,
        'declaration' => 'event-class',
        'symbol' => $constant,
        'source' => $eventClass['source'],
        'owner_source' => $eventClass['owner_source'],
    ]);
}

$rows = [];
foreach ($events as $moduleEvents) {
    foreach ($moduleEvents as $declarations) {
        usort($declarations, static fn (array $left, array $right): int => [$left['declaration'], $left['source']] <=> [$right['declaration'], $right['source']]);
        $rows[] = [
            'module' => $declarations[0]['module'],
            'event' => $declarations[0]['event'],
            'declarations' => $declarations,
            'owner_source' => $declarations[0]['owner_source'],
        ];
    }
}

usort($rows, static fn (array $left, array $right): int => [$left['module'], $left['event']] <=> [$right['module'], $right['event']]);

$outputPath = $repoRoot . '/docs/12-reference/event-types.md';
$content = renderEvents($rows, $catalogues, $eventClasses);

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

/** @param array<string, array<string, list<array<string, string>>>> $events */
function addEvent(array &$events, array $declaration): void
{
    $module = $declaration['module'];
    $event = $declaration['event'];
    $events[$module][$event] ??= [];

    foreach ($events[$module][$event] as $existing) {
        if ($existing['declaration'] === $declaration['declaration'] && $existing['source'] === $declaration['source']) {
            return;
        }
    }

    $events[$module][$event][] = $declaration;
}

/**
 * @param list<array{module: string, event: string, declarations: list<array<string, string>>, owner_source: string}> $rows
 * @param list<array{module: string, class: class-string, method: string, source: string, owner_source: string}> $catalogues
 * @param list<array{module: string, class: class-string, source: string, owner_source: string}> $eventClasses
 */
function renderEvents(array $rows, array $catalogues, array $eventClasses): string
{
    $counts = [];
    foreach ($rows as $row) {
        $counts[$row['module']] = ($counts[$row['module']] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);

    $lines = [
        '---',
        'title: Event Types',
        'description: Generated reference of module-owned runtime event types.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Event Types',
        '',
        '> Джерела істини: explicit event catalogues та `TYPE` constants, які формують `EventOwningModuleInterface::eventTypes()`.',
        '',
        'Ця сторінка описує **runtime event ownership vocabulary**. Вона не намагається вгадувати події через regex-сканування всього PHP-коду і не змішує event types з consumer subscriptions або фактичними outbox records.',
        '',
        '## Summary',
        '',
        '| Module | Event types | Runtime owner source |',
        '| --- | ---: | --- |',
    ];

    foreach ($counts as $module => $count) {
        $ownerSource = null;
        foreach ($rows as $row) {
            if ($row['module'] === $module) {
                $ownerSource = $row['owner_source'];
                break;
            }
        }
        $lines[] = sprintf('| `%s` | %d | `%s` |', table($module), $count, table((string) $ownerSource));
    }

    $lines[] = '';
    $lines[] = '## Catalogue';
    $lines[] = '';
    $lines[] = '| Module | Event type | Declaration | Symbol | Source | Runtime owner |';
    $lines[] = '| --- | --- | --- | --- | --- | --- |';

    foreach ($rows as $row) {
        $declarations = $row['declarations'];
        $lines[] = sprintf(
            '| `%s` | `%s` | %s | %s | %s | `%s` |',
            table($row['module']),
            table($row['event']),
            inlineCode(array_column($declarations, 'declaration')),
            inlineCode(array_column($declarations, 'symbol')),
            inlineCode(array_column($declarations, 'source')),
            table($row['owner_source']),
        );
    }

    $lines[] = '';
    $lines[] = '## Explicit source registry';
    $lines[] = '';
    $lines[] = 'Documentation tooling підключає event authority явно. Це дешевше, прозоріше і надійніше, ніж змушувати генератор інтерпретувати довільний PHP як археолог.';
    $lines[] = '';
    $lines[] = '| Module | Kind | Symbol | Source |';
    $lines[] = '| --- | --- | --- | --- |';

    foreach ($catalogues as $catalogue) {
        $lines[] = sprintf(
            '| `%s` | `catalogue` | `%s::%s()` | `%s` |',
            table($catalogue['module']),
            table($catalogue['class']),
            table($catalogue['method']),
            table($catalogue['source']),
        );
    }
    foreach ($eventClasses as $eventClass) {
        $lines[] = sprintf(
            '| `%s` | `event-class` | `%s::TYPE` | `%s` |',
            table($eventClass['module']),
            table($eventClass['class']),
            table($eventClass['source']),
        );
    }

    $lines[] = '';
    $lines[] = '## Scope';
    $lines[] = '';
    $lines[] = '- event type тут означає canonical string, ownership якого реєструє domain module у Kernel;';
    $lines[] = '- consumer subscriptions документуються окремо через extension points;';
    $lines[] = '- persistence/outbox records є runtime data, а не частиною static event catalogue;';
    $lines[] = '- `npm run docs:generate:check` ловить зміну значення зареєстрованого catalogue або `TYPE` constant без оновлення generated reference.';
    $lines[] = '';

    return implode("\n", $lines);
}

/** @param list<string> $values */
function inlineCode(array $values): string
{
    $values = array_values(array_unique($values));
    sort($values, SORT_STRING);
    return implode('<br>', array_map(static fn (string $value): string => sprintf('`%s`', table($value)), $values));
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
