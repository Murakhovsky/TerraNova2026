<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    'bin/spatial-worker.php',
    'bin/integration-worker.php',
    'bin/apply-migration.php',
] as $path) {
    $assert(!is_file($root . '/' . $path), 'Retired background runtime entrypoint restored: ' . $path);
}

$legacyCompose = $read('docker-compose.yml');
$assert(!str_contains($legacyCompose, "\n  worker:\n"), 'Legacy compose worker service must stay retired.');
$assert(!str_contains($legacyCompose, 'app/bootstrap_cli.php", "worker", "run'), 'Phalcon CLI worker command must stay retired.');
$assert(str_contains($legacyCompose, 'command: ["php", "bin/migrate.php", "up"]'), 'Compatibility schema bootstrap must use framework-neutral bin/migrate.php.');

$migrate = $read('bin/migrate.php');
$assert(!str_contains($migrate, 'Phalcon\\'), 'Framework-neutral migration entrypoint must not depend on Phalcon.');
$assert(str_contains($migrate, 'MigrationRunner'), 'Framework-neutral migration entrypoint must use canonical MigrationRunner.');

$symfonyCompose = $read('docker-compose.symfony.yml');
foreach ([
    "kernel-worker:\n",
    "spatial-worker:\n",
    "integration-worker:\n",
    'cos:kernel:worker',
    'cos:spatial:process',
    'cos:integration:n8n:process',
] as $needle) {
    $assert(str_contains($symfonyCompose, $needle), 'Canonical background runtime compose contract missing: ' . $needle);
}

$services = $read('symfony/config/services.yaml');
foreach ([
    'Kernel\\Operations\\Service\\WorkerSupervisor:',
    'Kernel\\Queue\\Service\\QueueWorker:',
    'Kernel\\Queue\\Service\\JobHandlerRegistry:',
    'Kernel\\Queue\\Handler\\AgentRunJobHandler:',
    'Domains\\Content\\Application\\Service\\ContentService:',
] as $needle) {
    $assert(str_contains($services, $needle), 'Canonical background runtime DI missing: ' . $needle);
}

foreach ([
    ['symfony/src/Command/KernelWorkerCommand.php', "name: 'cos:kernel:worker'"],
    ['symfony/src/Command/LegacySchemaMigrateCommand.php', "name: 'cos:legacy-schema:migrate'"],
    ['symfony/src/Command/LegacySchemaStatusCommand.php', "name: 'cos:legacy-schema:status'"],
    ['symfony/src/Command/SpatialProcessingCommand.php', "name: 'cos:spatial:process'"],
    ['symfony/src/Command/IntegrationOutboxCommand.php', "name: 'cos:integration:n8n:process'"],
] as [$path, $needle]) {
    $source = $read($path);
    $assert(str_contains($source, $needle), 'Canonical Symfony command missing: ' . $needle);
    $assert(!str_contains($source, 'Phalcon\\'), 'Canonical Symfony command depends on Phalcon: ' . $path);
}

$deploy = $read('deploy/symfony-dev.sh');
$assert(
    str_contains($deploy, 'for service in worker kernel-worker spatial-worker integration-worker scheduler; do'),
    'Symfony deploy must verify every canonical background runtime service.'
);

echo "Background runtime retirement boundary OK\n";
