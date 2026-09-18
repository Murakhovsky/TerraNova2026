<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$compose = (string) file_get_contents($root . '/docker-compose.symfony.yml');
$deploy = (string) file_get_contents($root . '/deploy/symfony-dev.sh');

foreach ([
    "worker:\n",
    "scheduler:\n",
    'init: true',
    'stop_grace_period: 30s',
    '--time-limit=3600',
] as $needle) {
    if (!str_contains($compose, $needle)) {
        throw new RuntimeException('Symfony runtime hardening contract missing: ' . $needle);
    }
}

foreach ([
    "{{.State.Running}}",
    "{{.RestartCount}}",
    'restart_count',
    'restarted unexpectedly',
] as $needle) {
    if (!str_contains($deploy, $needle)) {
        throw new RuntimeException('Symfony deploy readiness guard missing: ' . $needle);
    }
}

echo "Symfony worker and scheduler runtime hardening contract passed.\n";
