<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$record = (string) file_get_contents($root.'/symfony/src/Persistence/Doctrine/Entity/Engineering/WorkflowExecutionRecord.php');
$migration = (string) file_get_contents($root.'/symfony/migrations/Version20261004231500.php');

foreach ([
    '#[ORM\\Version]',
    "options: ['default' => 1]",
] as $needle) {
    if (!str_contains($record, $needle)) {
        throw new RuntimeException('Engineering workflow optimistic-lock mapping missing '.$needle);
    }
}

if (!str_contains($migration, 'ALTER TABLE cos_engineering_workflows MODIFY version INT NOT NULL DEFAULT 1')) {
    throw new RuntimeException('Engineering workflow version migration must provide DEFAULT 1 for Doctrine optimistic locking.');
}

echo "Engineering workflow optimistic-lock version contract passed.\n";
