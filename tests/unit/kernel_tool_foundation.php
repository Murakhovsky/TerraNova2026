<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolResult;
use Kernel\Tool\Service\ToolRegistry;

function expectTool(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class EchoTool implements ToolInterface
{
    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            'core.echo',
            'Echo structured input.',
            ['type' => 'object'],
            ['type' => 'object'],
            ToolEffect::READ,
        );
    }

    public function invoke(ToolInvocation $invocation): ToolResult
    {
        return ToolResult::success($invocation->input(), [
            'organization_id' => $invocation->organizationId()->value(),
            'correlation_id' => $invocation->correlationId(),
        ]);
    }
}

$definition = (new EchoTool())->definition();
expectTool($definition->name() === 'core.echo', 'Tool name should be canonical.');
expectTool(!$definition->effect()->hasSideEffects(), 'READ tool must be side-effect free.');
expectTool(ToolEffect::WRITE->hasSideEffects(), 'WRITE tool must report side effects.');
expectTool(ToolEffect::EXTERNAL->hasSideEffects(), 'EXTERNAL tool must report side effects.');

$invocation = new ToolInvocation(
    OrganizationId::fromString('default'),
    'CORE.ECHO',
    ['message' => 'hello'],
    'corr-1',
    UserId::fromString('1001'),
);
expectTool($invocation->toolName() === 'core.echo', 'Invocation should normalize tool name.');
expectTool($invocation->requestedBy()?->value() === '1001', 'Invocation should preserve actor identity.');

$registry = new ToolRegistry([new EchoTool()]);
expectTool($registry->find('CORE.ECHO') instanceof EchoTool, 'Registry lookup should be canonical and case-insensitive.');
expectTool(count($registry->definitions()) === 1, 'Registry should expose tool definitions.');

$result = $registry->find('core.echo')?->invoke($invocation);
expectTool($result instanceof ToolResult && $result->isSuccess(), 'Registered tool should execute.');
expectTool(($result->output()['message'] ?? null) === 'hello', 'Tool result should expose structured output.');
expectTool(($result->metadata()['organization_id'] ?? null) === 'default', 'Tool execution should preserve tenant context.');

$failure = ToolResult::failure('failed');
expectTool($failure->isFailure() && $failure->error() === 'failed', 'Failed tool result should expose its error.');

try {
    new ToolRegistry([new EchoTool(), new EchoTool()]);
    throw new RuntimeException('Duplicate tool registration must fail.');
} catch (InvalidArgumentException) {
}

try {
    new ToolDefinition('INVALID TOOL', 'bad');
    throw new RuntimeException('Invalid tool name must fail.');
} catch (InvalidArgumentException) {
}

echo "Kernel Tool foundation contract passed without Symfony kernel.\n";
