<?php
declare(strict_types=1);

namespace Kernel\Workflow\Service;

use InvalidArgumentException;
use Kernel\Agent\Contract\AgentRuntimeInterface;
use Kernel\Agent\Model\AgentContext;
use Kernel\Agent\Model\AgentRunStatus;
use Kernel\Tool\Contract\ToolRuntimeInterface;
use Kernel\Tool\Model\ToolExecutionStatus;
use Kernel\Tool\Model\ToolInvocation;
use Kernel\Workflow\Contract\ConditionEvaluatorInterface;
use Kernel\Workflow\Contract\SystemStepHandlerInterface;
use Kernel\Workflow\Contract\WorkflowEngineInterface;
use Kernel\Workflow\Contract\WorkflowStateManagerInterface;
use Kernel\Workflow\Model\Step\AgentStep;
use Kernel\Workflow\Model\Step\DecisionStep;
use Kernel\Workflow\Model\Step\HumanStep;
use Kernel\Workflow\Model\Step\Step;
use Kernel\Workflow\Model\Step\SystemStep;
use Kernel\Workflow\Model\Step\ToolStep;
use Kernel\Workflow\Model\Step\WaitStep;
use Kernel\Workflow\Model\StepExecution;
use Kernel\Workflow\Model\Transition;
use Kernel\Workflow\Model\WorkflowExecution;
use Kernel\Workflow\Model\WorkflowInstance;
use Kernel\Workflow\Model\WorkflowStatus;
use RuntimeException;
use Throwable;

final readonly class WorkflowEngine implements WorkflowEngineInterface
{
    public function __construct(
        private AgentRuntimeInterface $agents,
        private ToolRuntimeInterface $tools,
        private ConditionEvaluatorInterface $conditions,
        private SystemStepHandlerInterface $systems,
        private WorkflowStateManagerInterface $states,
        private int $maxInlineSteps = 100,
    ) {
        if ($maxInlineSteps < 1 || $maxInlineSteps > 10000) {
            throw new InvalidArgumentException('Workflow inline step limit must be between 1 and 10000.');
        }
    }

    public function start(WorkflowInstance $instance, array $input = []): WorkflowExecution
    {
        if (!$instance->enabled) throw new RuntimeException('Workflow instance is disabled.');

        $execution = new WorkflowExecution(bin2hex(random_bytes(16)), $instance, $input);
        $execution->transitionTo(WorkflowStatus::RUNNING, $this->states);
        return $this->drive($execution);
    }

    public function resume(WorkflowExecution $execution, array $payload = []): WorkflowExecution
    {
        if ($execution->status() !== WorkflowStatus::WAITING) {
            throw new RuntimeException('Only a waiting workflow can be resumed.');
        }

        $stepExecution = $execution->currentStepExecution();
        if ($stepExecution === null) throw new RuntimeException('Waiting workflow has no current step execution.');

        $execution->transitionTo(WorkflowStatus::RUNNING, $this->states);
        $stepExecution->resume();
        $stepExecution->complete($payload);
        $execution->recordOutput($stepExecution->stepId, $payload);

        try {
            if (!$this->advance($execution)) return $execution;
            return $this->drive($execution);
        } catch (Throwable $error) {
            $execution->fail($error->getMessage(), $this->states);
            return $execution;
        }
    }

    public function cancel(WorkflowExecution $execution): void
    {
        $execution->cancel($this->states);
    }

    private function drive(WorkflowExecution $execution): WorkflowExecution
    {
        for ($inline = 0; $inline < $this->maxInlineSteps && $execution->status() === WorkflowStatus::RUNNING; ++$inline) {
            $step = $execution->instance->workflow->definition->step($execution->currentStepId());
            $stepExecution = $execution->beginStep($step->id);

            try {
                if ($step instanceof HumanStep) {
                    $stepExecution->wait();
                    $execution->wait('human:' . $step->id, $this->states);
                    return $execution;
                }

                if ($step instanceof WaitStep) {
                    $stepExecution->wait();
                    $execution->wait('signal:' . $step->signal, $this->states);
                    return $execution;
                }

                if ($step instanceof AgentStep) {
                    if (!$this->executeAgent($step, $stepExecution, $execution)) return $execution;
                } elseif ($step instanceof ToolStep) {
                    $this->executeTool($step, $stepExecution, $execution);
                } elseif ($step instanceof SystemStep) {
                    $this->executeSystem($step, $stepExecution, $execution);
                } elseif ($step instanceof DecisionStep) {
                    $stepExecution->complete(['evaluated' => true]);
                    $execution->recordOutput($step->id, ['evaluated' => true]);
                } else {
                    throw new RuntimeException('Unsupported workflow step type: ' . $step::class);
                }

                if (!$this->advance($execution)) return $execution;
            } catch (Throwable $error) {
                if (in_array($stepExecution->status()->value, ['running', 'waiting'], true)) {
                    $stepExecution->fail($error->getMessage());
                }
                $execution->fail($error->getMessage(), $this->states);
                return $execution;
            }
        }

        if ($execution->status() === WorkflowStatus::RUNNING) {
            $execution->fail('Workflow inline step limit exceeded.', $this->states);
        }
        return $execution;
    }

    private function executeAgent(AgentStep $step, StepExecution $stepExecution, WorkflowExecution $execution): bool
    {
        $input = $this->resolveArray($step->input, $execution->context());
        $run = $this->agents->execute(
            $step->agent,
            new AgentContext(
                $execution->instance->organizationId,
                $execution->id . ':' . $step->id,
                $input,
                $execution->context(),
            ),
        );

        if ($run->status() === AgentRunStatus::WAITING) {
            $stepExecution->wait();
            $execution->wait('agent:' . $step->id, $this->states);
            return false;
        }
        if ($run->status() !== AgentRunStatus::COMPLETED || $run->output() === null) {
            throw new RuntimeException($run->error() ?? 'Agent step did not complete successfully.');
        }

        $output = [
            'content' => $run->output()->content,
            'structured' => $run->output()->structured,
            'provider' => $run->output()->provider,
            'model' => $run->output()->model,
            'usage' => $run->output()->usage,
            'metadata' => $run->output()->metadata,
        ];
        $stepExecution->complete($output);
        $execution->recordOutput($step->id, $output);
        return true;
    }

    private function executeTool(ToolStep $step, StepExecution $stepExecution, WorkflowExecution $execution): void
    {
        $invocation = new ToolInvocation(
            $execution->instance->organizationId,
            $step->toolName,
            $this->resolveArray($step->input, $execution->context()),
            $execution->id . ':' . $step->id,
        );
        $toolExecution = $this->tools->execute($invocation);
        if ($toolExecution->status() !== ToolExecutionStatus::COMPLETED || $toolExecution->result() === null) {
            throw new RuntimeException($toolExecution->error() ?? 'Tool step did not complete successfully.');
        }
        $output = [
            'output' => $toolExecution->result()->output(),
            'metadata' => $toolExecution->result()->metadata(),
            'attempts' => $toolExecution->attempts(),
        ];
        $stepExecution->complete($output);
        $execution->recordOutput($step->id, $output);
    }

    private function executeSystem(SystemStep $step, StepExecution $stepExecution, WorkflowExecution $execution): void
    {
        $output = $this->systems->execute($step, $this->resolveArray($step->payload, $execution->context()), $execution);
        $stepExecution->complete($output);
        $execution->recordOutput($step->id, $output);
    }

    private function advance(WorkflowExecution $execution): bool
    {
        $definition = $execution->instance->workflow->definition;
        $outgoing = $definition->outgoing($execution->currentStepId());
        if ($outgoing === []) {
            $execution->transitionTo(WorkflowStatus::COMPLETED, $this->states);
            return false;
        }

        $matching = [];
        $default = null;
        foreach ($outgoing as $transition) {
            if ($transition->condition === null) {
                $default = $transition;
                continue;
            }
            if ($this->conditions->matches($transition->condition, $execution->context())) $matching[] = $transition;
        }
        if (count($matching) > 1) {
            throw new RuntimeException('Workflow has multiple matching conditional transitions from ' . $execution->currentStepId());
        }

        /** @var ?Transition $selected */
        $selected = $matching[0] ?? $default;
        if ($selected === null) {
            throw new RuntimeException('Workflow has no matching transition from ' . $execution->currentStepId());
        }

        $execution->advanceTo($selected->to);
        return true;
    }

    /** @param array<string,mixed> $template @param array<string,mixed> $context @return array<string,mixed> */
    private function resolveArray(array $template, array $context): array
    {
        $resolved = [];
        foreach ($template as $key => $value) $resolved[$key] = $this->resolveValue($value, $context);
        return $resolved;
    }

    private function resolveValue(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            $resolved = [];
            foreach ($value as $key => $nested) $resolved[$key] = $this->resolveValue($nested, $context);
            return $resolved;
        }
        if (!is_string($value) || !str_starts_with($value, '$.')) return $value;

        $cursor = $context;
        $path = substr($value, 2);
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                throw new InvalidArgumentException('Workflow template path does not exist: ' . $value);
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }
}
