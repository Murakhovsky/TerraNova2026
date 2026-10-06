<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\AgentCapabilityRegistry;
use App\Engineering\Application\Agent\EngineeringAgentToolPermissionPolicy;
use App\Engineering\Application\Agent\EngineeringPolicyEngine;
use App\Engineering\Domain\Agent\AgentRole;

$root = dirname(__DIR__, 2);
$registry = new AgentCapabilityRegistry($root.'/symfony/config/engineering/agents');
$policy = new EngineeringPolicyEngine($registry);
$tools = new EngineeringAgentToolPermissionPolicy($registry);

$expectFailure = static function (callable $callable, string $message): void {
    try {
        $callable();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
};

$expectFailure(
    static fn () => $policy->assertExecutionAllowed(AgentRole::ENGINEERING_MANAGER, 'FEATURE', 'MEDIUM'),
    'Engineering Manager direct execution was accepted.',
);
$expectFailure(
    static fn () => $policy->assertExecutionAllowed(AgentRole::QA, 'FEATURE', 'MEDIUM'),
    'Legacy QA execution was accepted.',
);
$expectFailure(
    static fn () => $policy->assertExecutionAllowed(AgentRole::REVIEWER, 'FEATURE', 'MEDIUM', []),
    'Reviewer was accepted without Developer evidence.',
);

$developerHistory = [[
    'role' => AgentRole::DEVELOPER->value,
    'status' => 'COMPLETED',
    'agent_id' => 'developer:run-1',
    'actor_id' => 'developer:run-1',
]];
$policy->assertExecutionAllowed(AgentRole::REVIEWER, 'FEATURE', 'MEDIUM', $developerHistory);

$expectFailure(
    static fn () => $policy->assertExecutionAllowed(AgentRole::QA_EXECUTOR, 'FEATURE', 'MEDIUM', $developerHistory),
    'QA Executor was accepted without Reviewer evidence.',
);

$reviewedHistory = [
    ...$developerHistory,
    ['role' => AgentRole::REVIEWER->value, 'status' => 'COMPLETED', 'agent_id' => 'reviewer:run-2', 'actor_id' => 'reviewer:run-2'],
];
$policy->assertExecutionAllowed(AgentRole::QA_EXECUTOR, 'FEATURE', 'MEDIUM', $reviewedHistory);

$selfApprovalHistory = [
    ['role' => AgentRole::DEVELOPER->value, 'status' => 'COMPLETED', 'agent_id' => 'same-actor', 'actor_id' => 'same-actor'],
    ['role' => AgentRole::REVIEWER->value, 'status' => 'COMPLETED', 'agent_id' => 'same-actor', 'actor_id' => 'same-actor'],
];
$expectFailure(
    static fn () => $policy->assertExecutionAllowed(AgentRole::QA_EXECUTOR, 'FEATURE', 'HIGH', $selfApprovalHistory),
    'Identity-level self approval was accepted.',
);
$policy->assertExecutionAllowed(AgentRole::SECURITY_SPECIALIST, 'FEATURE', 'HIGH', $reviewedHistory);

$tools->assertRepositoryMutationAllowed(AgentRole::QA_EXECUTOR, [[
    'path' => 'tests/unit/example.php',
    'operation' => 'CREATE',
    'content' => '<?php',
]]);
$tools->assertRepositoryMutationAllowed(AgentRole::QA_EXECUTOR, [[
    'path' => 'symfony/tests/Functional/ExampleTest.php',
    'operation' => 'UPDATE',
    'content' => '<?php',
]]);
$expectFailure(
    static fn () => $tools->assertRepositoryMutationAllowed(AgentRole::QA_EXECUTOR, [[
        'path' => 'symfony/src/Backdoor.php',
        'operation' => 'CREATE',
        'content' => '<?php',
    ]]),
    'QA Executor escaped test roots.',
);
$expectFailure(
    static fn () => $tools->assertRepositoryMutationAllowed(AgentRole::REVIEWER, [[
        'path' => 'symfony/src/ReviewMutation.php',
        'operation' => 'CREATE',
        'content' => '<?php',
    ]]),
    'Reviewer repository mutation was accepted.',
);

echo "Engineering Agent Runtime V2 policy enforcement passed.\n";
