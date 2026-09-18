<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Observability\CorrelationId;
use Kernel\Observability\ExecutionContext;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;

function expectExecution(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$generated = CorrelationId::generate();
expectExecution((bool) preg_match('/^[a-f0-9]{32}$/', $generated->value()), 'Generated correlation id must be stable and log-safe.');

$context = new ExecutionContext(
    CorrelationId::fromString('corr-123'),
    'worker',
    OrganizationId::fromString('org-1'),
    UserId::fromString('42'),
);
expectExecution($context->toLogContext() === [
    'correlation_id' => 'corr-123',
    'source' => 'worker',
    'organization_id' => 'org-1',
    'actor_id' => '42',
], 'Execution context must expose stable structured-log fields.');

try {
    CorrelationId::fromString('../bad correlation');
    throw new RuntimeException('Invalid correlation id must be rejected.');
} catch (InvalidArgumentException) {
}

echo "Kernel execution context contract passed.\n";
