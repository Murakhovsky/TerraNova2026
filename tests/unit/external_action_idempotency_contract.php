<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Domains\Sales\Automation\Action\CreateFollowupTaskHandler;
use Domains\Sales\Automation\Action\ScheduleMeetingHandler;
use Domains\Sales\Automation\Action\SendMessageHandler;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\ActionHandlerInterface;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExternalActionIdempotency;

$makeAction = static fn (?string $key, string $id = 'action-42'): Action => new Action(
    id: $id,
    organizationId: 'org-1',
    type: 'sales.send_message',
    targetType: 'deal',
    targetId: 'deal-1',
    parameters: ['body' => 'Hello'],
    sourceType: 'agent',
    sourceId: 'agent-1',
    executionMode: 'automatic',
    riskLevel: 'low',
    idempotencyKey: $key,
    createdAt: new DateTimeImmutable(),
    status: ActionStatus::Queued,
    correlationId: 'corr-1',
);

if (ExternalActionIdempotency::resolve($makeAction('explicit-key')) !== 'explicit-key') {
    throw new RuntimeException('Explicit external action idempotency key was not preserved.');
}
if (ExternalActionIdempotency::resolve($makeAction(null)) !== 'action-42') {
    throw new RuntimeException('Action id was not used as the stable idempotency fallback.');
}
try {
    ExternalActionIdempotency::resolve($makeAction('   ', 'action-42'));
    throw new RuntimeException('Blank explicit idempotency key must be rejected.');
} catch (InvalidArgumentException) {
}

foreach ([SendMessageHandler::class, CreateFollowupTaskHandler::class, ScheduleMeetingHandler::class] as $handler) {
    if (!is_subclass_of($handler, IdempotentExternalActionHandlerInterface::class)) {
        throw new RuntimeException($handler . ' must declare the external idempotency contract.');
    }
    if (!is_subclass_of($handler, ActionHandlerInterface::class)) {
        throw new RuntimeException($handler . ' must remain a normal action handler.');
    }
}

$globalMethods = array_map(static fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass(ActionHandlerInterface::class))->getMethods());
sort($globalMethods);
if ($globalMethods !== ['execute', 'supports']) {
    throw new RuntimeException('Global ActionHandlerInterface contract changed unexpectedly.');
}

echo "External action idempotency contract invariants passed.\n";
