<?php
declare(strict_types=1);

namespace Kernel\Tool\Service;

use InvalidArgumentException;
use Kernel\Tool\Contract\ToolAuditInterface;
use Kernel\Tool\Contract\ToolInputValidatorInterface;
use Kernel\Tool\Contract\ToolPermissionCheckerInterface;
use Kernel\Tool\Contract\ToolRegistryInterface;
use Kernel\Tool\Contract\ToolRuntimeInterface;
use Kernel\Tool\Model\ToolExecution;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Tool\Model\ToolPermission;
use Kernel\Tool\Model\ToolResult;
use Kernel\Tool\Model\ToolRetryPolicy;
use Throwable;

final readonly class ToolRuntime implements ToolRuntimeInterface
{
    public function __construct(
        private ToolRegistryInterface $registry,
        private ToolPermissionCheckerInterface $permissions,
        private ToolInputValidatorInterface $validator,
        private ToolAuditInterface $audit,
        private ToolRetryPolicy $retryPolicy = new ToolRetryPolicy(),
    ) {}

    public function execute(ToolInvocation $invocation): ToolExecution
    {
        $tool = $this->registry->find($invocation->toolName());
        if ($tool === null) {
            throw new InvalidArgumentException('Unknown tool: ' . $invocation->toolName());
        }

        $definition = $tool->definition();
        $permission = ToolPermission::execute($definition->name());
        $execution = new ToolExecution(bin2hex(random_bytes(16)), $definition, $invocation, $permission);

        if (!$this->permissions->allows($permission, $definition, $invocation)) {
            $execution->deny('Permission denied: ' . $permission->name());
            $this->audit->record($execution);
            return $execution;
        }

        $execution->authorize();
        try {
            $this->validator->validate($definition, $invocation);
        } catch (Throwable $error) {
            $execution->reject(ToolResult::failure($error->getMessage(), ['validation' => true]));
            $this->audit->record($execution);
            return $execution;
        }

        $maxAttempts = $this->retryPolicy->attemptsFor($definition);
        $lastResult = null;

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            $execution->startAttempt();
            try {
                $lastResult = $tool->invoke($invocation);
            } catch (Throwable $error) {
                $lastResult = ToolResult::failure($error->getMessage(), ['exception' => $error::class]);
            }

            if ($lastResult->isSuccess()) {
                $execution->complete($lastResult);
                $this->audit->record($execution);
                return $execution;
            }
        }

        $execution->fail($lastResult ?? ToolResult::failure('Tool execution failed without a result.'));
        $this->audit->record($execution);
        return $execution;
    }
}
