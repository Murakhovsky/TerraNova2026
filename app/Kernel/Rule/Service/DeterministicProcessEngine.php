<?php
declare(strict_types=1);

namespace Kernel\Rule\Service;

use InvalidArgumentException;
use Kernel\Action\ActionProposal;
use Kernel\Event\DomainEvent;
use Kernel\Rule\ProcessEvaluation;
use Kernel\Rule\Rule;

final readonly class DeterministicProcessEngine
{
    public function __construct(private ConditionEvaluator $conditions)
    {
    }

    /** @param iterable<Rule> $rules @return list<ProcessEvaluation> */
    public function evaluate(DomainEvent $event, array $context, iterable $rules): array
    {
        $evaluations = [];
        foreach ($rules as $rule) {
            if ($rule->trigger !== $event->type) {
                continue;
            }

            $matched = $this->conditions->matches($rule->conditions, $context);
            $proposals = $matched ? $this->proposals($event, $rule) : [];
            $evaluations[] = new ProcessEvaluation(
                $rule,
                $matched,
                $proposals[0] ?? null,
                $proposals,
            );
        }

        usort(
            $evaluations,
            static fn (ProcessEvaluation $left, ProcessEvaluation $right): int =>
                $left->rule->priority <=> $right->rule->priority
        );

        return $evaluations;
    }

    private function proposal(DomainEvent $event, Rule $rule): ActionProposal
    {
        $effect = $rule->effect;
        if (($effect['type'] ?? null) !== 'CREATE_ACTION' || empty($effect['action_type'])) {
            throw new InvalidArgumentException(sprintf('Rule %s has an unsupported effect.', $rule->id));
        }

        $targetType = $this->template($effect['target_type'] ?? $event->aggregateType, $event);
        $targetId = $this->template($effect['target_id'] ?? $event->aggregateId, $event);
        $actionType = (string) $effect['action_type'];

        return new ActionProposal(
            $actionType,
            $targetType,
            $targetId,
            is_array($effect['parameters'] ?? null) ? $effect['parameters'] : [],
            'RULE',
            $rule->id,
            (string) ($effect['execution_mode'] ?? 'MANUAL'),
            (string) ($effect['risk_level'] ?? 'LOW'),
            implode(':', [$event->id, $rule->id, $actionType, $targetId ?? 'none']),
        );
    }

    /** @return list<ActionProposal> */
    private function proposals(DomainEvent $event, Rule $rule): array
    {
        if (is_array($rule->effect['actions'] ?? null)) {
            $proposals = [];
            foreach ($rule->effect['actions'] as $action) {
                if (!is_array($action) || empty($action['type'])) {
                    throw new InvalidArgumentException(sprintf('Rule %s contains an invalid action.', $rule->id));
                }
                $normalized = new Rule(
                    $rule->id,
                    $rule->organizationId,
                    $rule->name,
                    $rule->trigger,
                    $rule->conditions,
                    [
                        'type' => 'CREATE_ACTION',
                        'action_type' => $action['type'],
                        'target_type' => $action['target_type'] ?? $event->aggregateType,
                        'target_id' => $action['target_id'] ?? $event->aggregateId,
                        'parameters' => $action['parameters'] ?? [],
                        'execution_mode' => $action['execution_mode'] ?? 'MANUAL',
                        'risk_level' => $action['risk_level'] ?? 'LOW',
                    ],
                    $rule->version,
                    $rule->priority,
                );
                $proposals[] = $this->proposal($event, $normalized);
            }
            return $proposals;
        }

        return [$this->proposal($event, $rule)];
    }

    private function template(mixed $value, DomainEvent $event): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtr((string) $value, [
            '{{event.id}}' => $event->id,
            '{{event.aggregate_type}}' => $event->aggregateType,
            '{{event.aggregate_id}}' => $event->aggregateId,
        ]);
    }
}
