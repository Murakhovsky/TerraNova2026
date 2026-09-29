<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'app/bootstrap_cli.php',
    'app/config/services_cli.php',
    'app/Interfaces/Cli',
    'run',
] as $path) {
    $assert(!file_exists($root . '/' . $path), 'Retired Phalcon CLI surface restored: ' . $path);
}

$services = $read('symfony/config/services.yaml');
foreach ([
    'Kernel\\Event\\Service\\OutboxReplayService:',
    'Kernel\\Agent\\Contract\\AgentRetentionInterface:',
    'Domains\\Sales\\Application\\Service\\SalesDealStageHistoryRebuilder:',
    'Domains\\Sales\\Application\\Service\\SalesDealOwnerHistoryRebuilder:',
    'Domains\\Sales\\Application\\Service\\SalesHistoricalIntelligenceHealthService:',
] as $needle) {
    $assert(str_contains($services, $needle), 'Canonical Symfony admin service missing: ' . $needle);
}

$commands = [
    'symfony/src/Command/ConfigurationValidateCommand.php' => "name: 'cos:config:validate'",
    'symfony/src/Command/ConfigurationProvisionCommand.php' => "name: 'cos:config:provision'",
    'symfony/src/Command/OutboxRunCommand.php' => "name: 'cos:outbox:run'",
    'symfony/src/Command/OutboxReplayCommand.php' => "name: 'cos:outbox:replay'",
    'symfony/src/Command/QueueRunCommand.php' => "name: 'cos:queue:run'",
    'symfony/src/Command/QueueReplayDeadCommand.php' => "name: 'cos:queue:replay-dead'",
    'symfony/src/Command/AgentPurgeInputsCommand.php' => "name: 'cos:agent:purge-inputs'",
    'symfony/src/Command/SalesMonitorCommand.php' => "name: 'cos:sales:monitor'",
    'symfony/src/Command/SalesRebuildHistoryCommand.php' => "name: 'cos:sales:history:rebuild'",
    'symfony/src/Command/SalesHistoryHealthCommand.php' => "name: 'cos:sales:history:health'",
    'symfony/src/Command/KernelVersionCommand.php' => "name: 'cos:kernel:version'",
];

foreach ($commands as $path => $needle) {
    $source = $read($path);
    $assert(str_contains($source, $needle), 'Canonical Symfony CLI command missing: ' . $needle);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical CLI command depends on Phalcon: ' . $path);
}

foreach ([
    'docker-compose.yml',
    'docker-compose.symfony.yml',
    'deploy/dev.sh',
    'deploy/symfony-dev.sh',
    'README.md',
    'docs/09-development/local-setup.md',
    'docs/sales/EPIC4_RUNBOOK.md',
] as $path) {
    $source = $read($path);
    $assert(!str_contains($source, 'app/bootstrap_cli.php'), 'Active runtime/docs still reference retired Phalcon CLI bootstrap: ' . $path);
}

echo "Phalcon CLI retirement boundary OK\n";
