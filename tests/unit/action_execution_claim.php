<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Action\Action;
use Kernel\Action\ActionExecutionClaim;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionRepositoryInterface;

$action = new Action(
    'action-1', 'org-1', 'sales.message.send', 'lead', 'lead-1', [],
    'SYSTEM', 'test', 'AUTO', 'LOW', 'action-1', new DateTimeImmutable(),
    ActionStatus::Running, 'corr-1',
);
$claim = new ActionExecutionClaim($action, 2, 'worker-2');
if ($claim->action !== $action || $claim->attempt !== 2 || $claim->workerId !== 'worker-2') {
    throw new RuntimeException('Action execution claim did not preserve ownership identity.');
}

foreach ([[0, 'worker'], [1, '']] as [$attempt, $worker]) {
    try {
        new ActionExecutionClaim($action, $attempt, $worker);
        throw new RuntimeException('Expected invalid action execution claim rejection.');
    } catch (InvalidArgumentException) {
    }
}

$repository = new ReflectionClass(ActionRepositoryInterface::class);
foreach (['claim', 'claimNext'] as $methodName) {
    $returnType = (string) $repository->getMethod($methodName)->getReturnType();
    if ($returnType !== '?Kernel\\Action\\ActionExecutionClaim') {
        throw new RuntimeException(sprintf('%s() must return an execution claim, got %s.', $methodName, $returnType));
    }
}
$finishParameter = $repository->getMethod('finish')->getParameters()[0] ?? null;
if ($finishParameter === null || (string) $finishParameter->getType() !== 'Kernel\\Action\\ActionExecutionClaim') {
    throw new RuntimeException('finish() must require the exact execution claim.');
}

$mysqlSource = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/Persistence/MySql/Action/MysqlActionRepository.php'
);
foreach ([
    "ORDER BY attempt DESC LIMIT 1 FOR UPDATE",
    "attempt = :attempt AND worker_id = :worker_id AND status = 'RUNNING'",
    "WHERE status = 'RUNNING' AND started_at < :cutoff ORDER BY started_at FOR UPDATE",
    "SET status = 'FAILED', error = 'Worker lease expired', finished_at = NOW(6)",
] as $ownershipInvariant) {
    if (!str_contains($mysqlSource, $ownershipInvariant)) {
        throw new RuntimeException('Missing action execution ownership invariant: ' . $ownershipInvariant);
    }
}
if (str_contains(
    $mysqlSource,
    "WHERE action_id = :action_id AND status = 'RUNNING' ORDER BY attempt DESC LIMIT 1"
)) {
    throw new RuntimeException('Action completion still targets whichever attempt happens to be latest.');
}

$serviceSource = (string) file_get_contents($root . '/app/Kernel/Action/Service/ActionService.php');
if (!str_contains($serviceSource, '$this->actions->finish($claim, $result)')) {
    throw new RuntimeException('ActionService does not preserve the execution claim through completion.');
}
if (!str_contains($serviceSource, "'execution_attempt' => \$claim->attempt")) {
    throw new RuntimeException('Action audit trail is missing execution attempt identity.');
}

echo "Action execution claim invariants passed.\n";
