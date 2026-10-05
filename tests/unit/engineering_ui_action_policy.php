<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

use App\Engineering\Application\Service\EngineeringUiActionResolver;

$resolver = new EngineeringUiActionResolver();

$draft = $resolver->resolve(['status' => 'NEW'], null);
if (!$draft['queue'] || !$draft['run'] || !$draft['edit'] || $draft['cancel']) {
    throw new RuntimeException('Draft Engineering UI actions are inconsistent.');
}

$running = $resolver->resolve(
    ['status' => 'DEVELOPMENT_RUNNING'],
    ['state' => 'DEVELOPMENT_RUNNING', 'status' => 'RUNNING'],
    'HEALTHY',
);
if (!$running['continue'] || !$running['cancel'] || $running['retry']) {
    throw new RuntimeException('Running Engineering UI actions are inconsistent.');
}

$stalled = $resolver->resolve(
    ['status' => 'DEVELOPMENT_RUNNING'],
    ['state' => 'DEVELOPMENT_RUNNING', 'status' => 'RUNNING'],
    'STALLED',
);
if (!$stalled['resume'] || $stalled['continue'] || !$stalled['cancel']) {
    throw new RuntimeException('Stalled Engineering UI actions are inconsistent.');
}

$waiting = $resolver->resolve(
    ['status' => 'HUMAN_DECISION_REQUIRED'],
    ['state' => 'HUMAN_DECISION_REQUIRED', 'status' => 'WAITING'],
    'WAITING',
    1,
);
if (!$waiting['resolve_human'] || $waiting['continue'] || !$waiting['cancel']) {
    throw new RuntimeException('Human-decision Engineering UI actions are inconsistent.');
}

$cancelled = $resolver->resolve(
    ['status' => 'CANCELLED'],
    ['state' => 'CANCELLED', 'status' => 'CANCELLED'],
    'TERMINAL',
);
if (!$cancelled['retry'] || !$cancelled['delete'] || $cancelled['cancel'] || $cancelled['continue']) {
    throw new RuntimeException('Cancelled Engineering UI actions are inconsistent.');
}

$completed = $resolver->resolve(
    ['status' => 'DONE'],
    ['state' => 'DONE', 'status' => 'COMPLETED'],
    'TERMINAL',
);
if ($completed['cancel'] || $completed['continue'] || $completed['retry']) {
    throw new RuntimeException('Completed Engineering UI exposes an invalid transition.');
}

echo "Engineering UI action policy passed.\n";
