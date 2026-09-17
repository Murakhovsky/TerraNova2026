<?php
declare(strict_types=1);

namespace Kernel\Tool\Model;

use InvalidArgumentException;
use LogicException;

final class ToolExecution
{
    private ToolExecutionStatus $status = ToolExecutionStatus::CREATED;
    private int $attempts = 0;
    private ?ToolResult $result = null;
    private ?string $error = null;

    public function __construct(
        public readonly string $id,
        public readonly ToolDefinition $definition,
        public readonly ToolInvocation $invocation,
        public readonly ToolPermission $permission,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('Tool execution id cannot be empty.');
        }
        if ($definition->name() !== $invocation->toolName()) {
            throw new InvalidArgumentException('Tool execution definition must match invocation.');
        }
    }

    public function status(): ToolExecutionStatus { return $this->status; }
    public function attempts(): int { return $this->attempts; }
    public function result(): ?ToolResult { return $this->result; }
    public function error(): ?string { return $this->error; }

    public function authorize(): void
    {
        $this->expect(ToolExecutionStatus::CREATED);
        $this->status = ToolExecutionStatus::AUTHORIZED;
    }

    public function deny(string $reason): void
    {
        $this->expect(ToolExecutionStatus::CREATED);
        $this->status = ToolExecutionStatus::DENIED;
        $this->error = trim($reason) !== '' ? trim($reason) : 'Tool execution denied.';
        $this->result = ToolResult::failure($this->error, ['execution_id' => $this->id]);
    }

    public function reject(ToolResult $result): void
    {
        $this->expect(ToolExecutionStatus::AUTHORIZED);
        if ($result->isSuccess()) {
            throw new LogicException('Successful result cannot reject a tool execution.');
        }
        $this->status = ToolExecutionStatus::FAILED;
        $this->result = $result;
        $this->error = $result->error();
    }

    public function startAttempt(): void
    {
        if (!in_array($this->status, [ToolExecutionStatus::AUTHORIZED, ToolExecutionStatus::RUNNING], true)) {
            throw new LogicException('Tool execution is not authorized for execution.');
        }
        $this->status = ToolExecutionStatus::RUNNING;
        ++$this->attempts;
    }

    public function complete(ToolResult $result): void
    {
        $this->expect(ToolExecutionStatus::RUNNING);
        if ($result->isFailure()) {
            throw new LogicException('Failed result cannot complete a tool execution.');
        }
        $this->status = ToolExecutionStatus::COMPLETED;
        $this->result = $result;
    }

    public function fail(ToolResult $result): void
    {
        $this->expect(ToolExecutionStatus::RUNNING);
        if ($result->isSuccess()) {
            throw new LogicException('Successful result cannot fail a tool execution.');
        }
        $this->status = ToolExecutionStatus::FAILED;
        $this->result = $result;
        $this->error = $result->error();
    }

    private function expect(ToolExecutionStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new LogicException(sprintf('Invalid tool execution transition from %s.', $this->status->value));
        }
    }
}
