<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$deploy = (string) file_get_contents($root . '/deploy/dev.sh');
$compose = (string) file_get_contents($root . '/docker-compose.yml');
$workflow = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');

foreach ([
    'WORKER_ID=',
    "{{.State.Status}}",
    "{{.RestartCount}}",
    'Worker container is not ready',
    'logs --no-color --tail=250 worker',
    'worker_structured_log',
    'exit 29',
    'Worker container is ready: running with restart_count=0',
] as $needle) {
    if (!str_contains($deploy, $needle)) {
        throw new RuntimeException('Worker deployment readiness contract is missing: ' . $needle);
    }
}

if (!str_contains($compose, 'command: ["php", "app/bootstrap_cli.php", "worker", "run"]')) {
    throw new RuntimeException('Worker compose command must remain explicit.');
}
if (!str_contains($compose, "worker:\n") || !str_contains($compose, 'restart: unless-stopped')) {
    throw new RuntimeException('Worker compose service/restart policy is missing.');
}

if (!str_contains($workflow, 'bash deploy/dev.sh')) {
    throw new RuntimeException('AWS dev deployment must execute the guarded deploy/dev.sh script.');
}

$successPosition = strpos($deploy, 'DEV deployment completed successfully.');
$workerReadyPosition = strpos($deploy, 'Worker container is ready: running with restart_count=0');
if ($successPosition === false || $workerReadyPosition === false || $workerReadyPosition > $successPosition) {
    throw new RuntimeException('Deployment success must only be emitted after worker readiness is verified.');
}

echo "Deployment worker readiness contract passed.\n";
