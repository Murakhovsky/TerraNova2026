<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$legacyDeploy = (string) file_get_contents($root . '/deploy/dev.sh');
$symfonyDeploy = (string) file_get_contents($root . '/deploy/symfony-dev.sh');
$legacyCompose = (string) file_get_contents($root . '/docker-compose.yml');
$symfonyCompose = (string) file_get_contents($root . '/docker-compose.symfony.yml');
$workflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');

if (str_contains($legacyCompose, "\n  worker:\n")) {
    throw new RuntimeException('Retired legacy worker service returned to docker-compose.yml.');
}
if (str_contains($legacyCompose, 'app/bootstrap_cli.php", "worker", "run')) {
    throw new RuntimeException('Retired Phalcon worker command returned to docker-compose.yml.');
}

foreach ([
    "kernel-worker:\n",
    'cos:kernel:worker',
    'restart: unless-stopped',
] as $needle) {
    if (!str_contains($symfonyCompose, $needle)) {
        throw new RuntimeException('Canonical Kernel worker compose contract is missing: ' . $needle);
    }
}

foreach ([
    'for service in worker kernel-worker spatial-worker integration-worker scheduler; do',
    'Symfony $service container is missing.',
    '{{.State.Running}}',
    '{{.RestartCount}}',
    'Messenger, Kernel, Spatial, integration workers and Symfony Scheduler are healthy.',
] as $needle) {
    if (!str_contains($symfonyDeploy, $needle)) {
        throw new RuntimeException('Canonical Kernel worker deployment readiness contract is missing: ' . $needle);
    }
}

if (!str_contains($legacyDeploy, 'bash deploy/symfony-dev.sh')) {
    throw new RuntimeException('Compatibility deployment must deploy the canonical Symfony runtime.');
}
if (is_file($root . '/bin/spatial-worker.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/spatial-worker.php');
}

if (is_file($root . '/bin/integration-worker.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/integration-worker.php');
}

if (is_file($root . '/bin/telegram-worker.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/telegram-worker.php');
}

if (is_file($root . '/bin/apply-migration.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/apply-migration.php');
}

if (!str_contains($workflow, 'bash deploy/dev.sh')) {
    throw new RuntimeException('AWS dev deployment must execute the guarded deploy/dev.sh script.');
}

echo "Deployment worker readiness contract passed.\n";
