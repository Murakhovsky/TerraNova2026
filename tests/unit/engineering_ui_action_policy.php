<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = $root.'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

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
if ($running['continue'] || !$running['cancel'] || $running['retry'] || $running['resume']) {
    throw new RuntimeException('Active healthy Engineering workflow must not expose a manual continue/resume action.');
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

$ready = $resolver->resolve(
    ['status' => 'READY_FOR_HUMAN_APPROVAL'],
    ['state' => 'READY_FOR_HUMAN_APPROVAL', 'status' => 'WAITING'],
    'WAITING',
);
if (!$ready['finalize'] || $ready['resolve_human'] || $ready['continue'] || $ready['retry']) {
    throw new RuntimeException('READY_FOR_HUMAN_APPROVAL Engineering UI actions are inconsistent.');
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
