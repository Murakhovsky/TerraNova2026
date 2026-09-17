<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Shared\Domain\OrganizationId;
use Kernel\Tool\Contract\ToolAuditInterface;
use Kernel\Tool\Contract\ToolInterface;
use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Model\ToolDefinition;
use Kernel\Tool\Model\ToolEffect;
use Kernel\Tool\Model\ToolExecution;
use Kernel\Tool\Model\ToolExecutionStatus;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;
use Kernel\Tool\Model\ToolResult;
use Kernel\Tool\Model\ToolRetryPolicy;
use Kernel\Tool\Service\JsonSchemaToolInputValidator;
use Kernel\Tool\Service\ToolRegistry;
use Kernel\Tool\Service\ToolRuntime;

function toolRuntimeExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$readTool = new class implements ToolInterface {
    public int $calls = 0;
    public function definition(): ToolDefinition { return new ToolDefinition('crm.customer.read', 'read', ['type'=>'object','required'=>['id'],'properties'=>['id'=>['type'=>'integer']]], [], ToolEffect::READ); }
    public function invoke(ToolInvocation $invocation): ToolResult { ++$this->calls; return $this->calls < 3 ? ToolResult::failure('temporary') : ToolResult::success(['id'=>$invocation->input()['id']]); }
};
$writeTool = new class implements ToolInterface {
    public int $calls = 0;
    public function definition(): ToolDefinition { return new ToolDefinition('crm.customer.write', 'write', ['type'=>'object'], [], ToolEffect::WRITE); }
    public function invoke(ToolInvocation $invocation): ToolResult { ++$this->calls; return ToolResult::failure('write failed'); }
};
$permissions = new class implements ToolPermissionCheckerInterface {
    public bool $allow = true;
    public function allows(ToolPermission $permission, ToolDefinition $definition, ToolInvocation $invocation): bool { return $this->allow; }
};
$audit = new class implements ToolAuditInterface {
    public array $records = [];
    public function record(ToolExecution $execution): void { $this->records[] = [$execution->status()->value, $execution->attempts()]; }
};
$registry = new ToolRegistry([$readTool, $writeTool]);
$runtime = new ToolRuntime($registry, $permissions, new JsonSchemaToolInputValidator(), $audit, new ToolRetryPolicy(3));
$org = OrganizationId::fromString('default');

$execution = $runtime->execute(new ToolInvocation($org, 'crm.customer.read', ['id'=>42], 'corr-read'));
toolRuntimeExpect($execution->status() === ToolExecutionStatus::COMPLETED, 'READ tool should complete after retries.');
toolRuntimeExpect($execution->attempts() === 3 && $readTool->calls === 3, 'READ tool should use configured retries.');
toolRuntimeExpect(($execution->result()?->output()['id'] ?? null) === 42, 'Tool output must be preserved.');

$writeExecution = $runtime->execute(new ToolInvocation($org, 'crm.customer.write', [], 'corr-write'));
toolRuntimeExpect($writeExecution->status() === ToolExecutionStatus::FAILED, 'WRITE tool should fail normally.');
toolRuntimeExpect($writeExecution->attempts() === 1 && $writeTool->calls === 1, 'WRITE tool must not auto-retry side effects.');

$permissions->allow = false;
$denied = $runtime->execute(new ToolInvocation($org, 'crm.customer.read', ['id'=>7], 'corr-denied'));
toolRuntimeExpect($denied->status() === ToolExecutionStatus::DENIED && $denied->attempts() === 0, 'Denied tool must never execute.');
toolRuntimeExpect($denied->permission->name() === 'tool.crm.customer.read.execute', 'Tool permission must be canonical and explicit.');

$permissions->allow = true;
$invalid = $runtime->execute(new ToolInvocation($org, 'crm.customer.read', ['id'=>'wrong'], 'corr-invalid'));
toolRuntimeExpect($invalid->status() === ToolExecutionStatus::FAILED && $invalid->attempts() === 0, 'Invalid input must fail before tool invocation.');
toolRuntimeExpect(($invalid->result()?->metadata()['validation'] ?? false) === true, 'Validation failure must be explicit.');
toolRuntimeExpect($readTool->calls === 3, 'Validation failure must not invoke the Tool implementation.');
toolRuntimeExpect(count($audit->records) === 4, 'Every terminal execution must be audited exactly once.');

echo "Kernel Tool Runtime contract passed.\n";
