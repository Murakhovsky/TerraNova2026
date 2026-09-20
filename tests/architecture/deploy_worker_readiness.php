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
if (str_contains($legacyDeploy, 'build --pull php worker migrate')) {
    throw new RuntimeException('Compatibility deploy still tries to build the retired legacy worker service.');
}
if (!str_contains($legacyDeploy, 'build --pull php migrate')) {
    throw new RuntimeException('Compatibility deploy must build only the remaining PHP and migration images.');
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
    'for service in worker kernel-worker spatial-worker integration-worker telegram-worker scheduler; do',
    'Symfony $service container is missing.',
    '{{.State.Running}}',
    '{{.RestartCount}}',
    'Messenger, Kernel, Spatial, integration, Telegram workers and Symfony Scheduler are healthy.',
] as $needle) {
    if (!str_contains($symfonyDeploy, $needle)) {
        throw new RuntimeException('Canonical Kernel worker deployment readiness contract is missing: ' . $needle);
    }
}

if (!str_contains($legacyDeploy, 'structured_log "$PHP_ID" "application"')) {
    throw new RuntimeException('Compatibility deploy must expose structured application logs when /cos health fails.');
}

if (!str_contains($legacyDeploy, 'bash deploy/symfony-dev.sh')) {
    throw new RuntimeException('Compatibility deployment must deploy the canonical Symfony runtime.');
}
if (!str_contains($legacyDeploy, '"${DOCKER[@]}" exec cos-symfony-php-1 php bin/console cos:architecture:smoke')) {
    throw new RuntimeException('Compatibility deploy must execute Architecture smoke through the resolved Docker command.');
}
if (str_contains($legacyDeploy, 'docker compose -f docker-compose.symfony.yml exec -T php php bin/console cos:architecture:smoke')) {
    throw new RuntimeException('Compatibility deploy must not bypass Docker permissions/env handling for Architecture smoke.');
}
if (!str_contains($legacyDeploy, 'http://127.0.0.1:8081/health/dependencies')) {
    throw new RuntimeException('Compatibility deploy must use Symfony dependency readiness after runtime deployment.');
}
if (str_contains($legacyDeploy, 'http://127.0.0.1:8081/api/v1/health')) {
    throw new RuntimeException('Deploy must not block on operational health; DEAD historical work is not a readiness failure.');
}
if (is_file($root . '/bin/spatial-worker.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/spatial-worker.php');
}

if (is_file($root . '/bin/integration-worker.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/integration-worker.php');
}

if (is_file($root . '/bin/apply-migration.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/apply-migration.php');
}
if (is_file($root . '/bin/telegram-worker.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/telegram-worker.php');
}
if (is_file($root . '/bin/telegram-health.php')) {
    throw new RuntimeException('Retired runtime entrypoint restored: bin/telegram-health.php');
}

if (!str_contains($workflow, 'bash deploy/dev.sh')) {
    throw new RuntimeException('AWS dev deployment must execute the guarded deploy/dev.sh script.');
}

echo "Deployment worker readiness contract passed.\n";
