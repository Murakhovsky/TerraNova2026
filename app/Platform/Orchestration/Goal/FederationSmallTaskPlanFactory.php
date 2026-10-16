<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Bounded, human-authored Sales task scenario, independent of Golden 50.
 * Plan proposal only: the canonical Policy, Approval and Workflow gates
 * retain sole authority over every external Action.
 */
final readonly class FederationSmallTaskPlanFactory
{
    public const CAPABILITY = 'sales.create_task';
    public const CRITERIA = ['sales.local_tasks_created', 'sales.won_deals', 'sales.closed_deals'];

    public static function supports(GoalSpecification $goal): bool
    {
        return count($goal->criteria) === 1
            && in_array($goal->criteria[0]['id'] ?? null, self::CRITERIA, true)
            && ($goal->criteria[0]['operator'] ?? null) === 'at_least';
    }

    /**
     * @param list<array{deal_id:string,title:string,due_in_minutes:int}> $tasks
     * @return array{plan_id:string,steps:list<array<string,mixed>>}
     */
    public function build(GoalSpecification $goal, array $tasks): array
    {
        if (!self::supports($goal) || !in_array(self::CAPABILITY, $goal->allowedCapabilities, true)) {
            throw new DomainException('This Goal has no approved Sales task planning capability.');
        }
        if (!array_is_list($tasks) || count($tasks) < 1 || count($tasks) > 3) {
            throw new DomainException('Small scenario must contain between one and three operations.');
        }
        $steps = [];
        foreach ($tasks as $i => $task) {
            $dealId = $task['deal_id'] ?? null;
            $title = $task['title'] ?? null;
            $minutes = $task['due_in_minutes'] ?? null;
            if (!is_string($dealId) || !preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $dealId)
                || !is_string($title) || trim($title) === '' || mb_strlen($title) > 220
                || !is_int($minutes) || $minutes < 1 || $minutes > 43200) {
                throw new DomainException('A task needs an existing Deal ID, title and a valid deadline.');
            }
            $id = 'task-' . ($i + 1);
            $steps[] = [
                'id' => $id,
                'depends_on' => $i === 0 ? [] : ['task-' . $i],
                'capability_id' => self::CAPABILITY,
                'capability_version' => '1.0.0',
                'input' => [
                    'target_type' => 'deal',
                    'target_id' => $dealId,
                    'parameters' => ['title' => trim($title), 'due_in_minutes' => $minutes],
                ],
            ];
        }
        $id = 'plan-' . substr(hash('sha256', json_encode([
            'sales-task-scenario-v1', $goal->organizationId, $goal->goalId, $goal->version, $steps,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 24);
        return ['plan_id' => $id, 'steps' => $steps];
    }
}
