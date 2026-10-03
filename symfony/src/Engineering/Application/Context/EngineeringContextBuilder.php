<?php
declare(strict_types=1);

namespace App\Engineering\Application\Context;

use App\Engineering\Domain\Agent\EngineeringAgentTask;

final class EngineeringContextBuilder
{
    public function build(
        EngineeringAgentTask $task,
        RepositoryContextMap $repository,
        array $globalRules = [],
        array $roleInstructions = [],
        array $artifacts = [],
    ): array {
        return [
            'global_rules' => $globalRules,
            'role_instructions' => $roleInstructions,
            'current_objective' => $task->objective,
            'inputs' => $task->inputs,
            'constraints' => $task->constraints,
            'completion_criteria' => $task->completionCriteria,
            'artifacts' => $artifacts,
            'repository' => [
                'trust' => 'UNTRUSTED_REPOSITORY_CONTENT',
                'map' => $repository->toArray(),
            ],
        ];
    }
}
