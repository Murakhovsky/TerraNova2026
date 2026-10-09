<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Acceptance;

use App\Engineering\Application\Agent\EngineeringAgentRunResult;
use App\Engineering\Application\Agent\EngineeringAgentRunnerInterface;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;

final class DeterministicEngineeringAgentRunner implements EngineeringAgentRunnerInterface
{
    public function run(EngineeringAgentTask $task, string $organizationId, string $correlationId): EngineeringAgentRunResult
    {
        $crashRole = strtoupper(trim((string) getenv('COS_ENGINEERING_FIXTURE_CRASH_ROLE')));
        if ($crashRole !== '' && $crashRole === $task->role->value) {
            // Deliberately terminate the PHP process after AgentRun persistence and before
            // the stage executor can catch/finalize it. The recovery integration test
            // uses this to prove stale RUNNING recovery after a real process interruption.
            exit(91);
        }

        $scenario = $this->scenario($task);
        $output = match ($task->role) {
            AgentRole::PRODUCT_REQUIREMENTS => $this->product($task, $scenario),
            AgentRole::QA_PLANNER => $this->qaPlan($task),
            AgentRole::PRINCIPAL_ARCHITECT => $this->architecture($task),
            AgentRole::DEVELOPER => $this->developer($task),
            AgentRole::REVIEWER => $this->review($task, $scenario),
            AgentRole::QA_EXECUTOR => $this->qaExecution($task),
            default => throw new \RuntimeException('V0.1 deterministic acceptance runner does not support '.$task->role->value.'.'),
        };

        return new EngineeringAgentRunResult(
            runId: EngineeringId::generate(),
            role: $task->role,
            status: 'completed',
            structuredOutput: $output,
            provider: 'fixture',
            model: 'engineering-v01-acceptance',
            usage: [
                'input_tokens' => 100,
                'output_tokens' => 100,
                'cost_amount' => '0.000100',
            ],
            error: null,
            technicalRetries: 0,
            steps: [],
        );
    }

    private function product(EngineeringAgentTask $task, string $scenario): array
    {
        $attempt = max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1));
        $requiresDecision = $scenario === 'human-gate' && $attempt === 1;
        $marker = '[scenario:'.$scenario.']';
        $question = [
            'id' => 'HD-001',
            'question' => 'Choose the deterministic acceptance behavior.',
            'options' => [
                ['id' => 'CONTINUE', 'label' => 'Continue with the fixture contract.'],
                ['id' => 'CANCEL', 'label' => 'Cancel the fixture feature.'],
            ],
            'recommended_option' => 'CONTINUE',
        ];

        return [
            'status' => $requiresDecision ? 'HUMAN_DECISION_REQUIRED' : 'SPECIFICATION_READY',
            'feature' => [
                'title' => 'Engineering V0.1 acceptance '.$marker,
                'type' => 'FEATURE',
                'business_goal' => 'Verify the full persisted Engineering V0.1 lifecycle '.$marker,
                'user_problem' => 'Release evidence must exercise the real orchestration path.',
                'current_behavior' => 'Acceptance coverage is being verified.',
                'expected_behavior' => 'The feature reaches READY_FOR_HUMAN_APPROVAL without unauthorized mutation.',
                'scope' => ['Persist one bounded fixture implementation.'],
                'out_of_scope' => ['Production deployment.'],
                'affected_areas' => ['Engineering'],
                'user_roles' => ['engineering_operator'],
                'functional_requirements' => ['Execute the complete Engineering lifecycle.'],
                'non_functional_requirements' => ['Preserve idempotency and revision consistency.'],
                'acceptance_criteria' => [
                    ['id' => 'AC-001', 'description' => 'The bounded fixture behavior is implemented and verified.', 'verification_type' => 'automated'],
                ],
                'dependencies' => [],
                'constraints' => ['Human merge remains mandatory.'],
                'risks' => [],
                'assumptions' => ['Deterministic external adapters are test-only.'],
                'open_questions' => [],
                'priority' => 'P1',
                'complexity' => 'S',
            ],
            'context_map' => [],
            'tasks' => [
                [
                    'id' => 'TASK-001',
                    'type' => 'IMPLEMENTATION',
                    'title' => 'Implement bounded fixture target',
                    'description' => 'Create or correct the acceptance fixture target.',
                    'assigned_role' => 'DEVELOPER',
                    'dependencies' => [],
                    'acceptance_criteria' => ['AC-001'],
                ],
            ],
            'risks' => [],
            'assumptions' => ['Deterministic external adapters are test-only.'],
            'open_questions' => $requiresDecision ? [$question] : [],
        ];
    }

    private function qaPlan(EngineeringAgentTask $task): array
    {
        $featureId = $task->featureId;
        return [
            'phase' => 'PLAN',
            'status' => 'PLAN_READY',
            'feature_id' => $featureId,
            'tested_revision' => null,
            'pull_request' => null,
            'test_plan' => $this->testPlan($featureId),
            'test_changes' => [],
        ];
    }

    private function architecture(EngineeringAgentTask $task): array
    {
        $target = 'symfony/src/Engineering/Acceptance/V01FixtureTarget.php';

        return [
            'status' => 'APPROVED',
            'architecture_decision' => [
                'affected_domains' => ['Engineering'],
                'primary_owner_domain' => 'Engineering',
                'bounded_context' => 'Engineering acceptance fixture',
                'current_architecture' => 'The production Engineering orchestration path is reused unchanged.',
                'proposed_solution' => 'Create one bounded file through the normal Developer repository mutation contract.',
                'components' => ['Engineering acceptance fixture'],
                'interfaces' => [],
                'data_flow' => ['Product', 'QA Plan', 'Architect', 'Developer', 'Reviewer', 'QA'],
                'dependencies' => [],
                'database_changes' => [],
                'api_changes' => [],
                'events' => [],
                'identity' => 'No identity surface change.',
                'permissions' => 'No permission surface change.',
                'tenant_isolation' => 'No tenant data access is introduced.',
                'security' => 'No security boundary change.',
                'migration_strategy' => null,
                'backward_compatibility' => 'No public contract change.',
                'observability' => 'Engineering execution journal remains authoritative.',
                'testing_strategy' => 'Run deterministic V0.1 integration acceptance.',
                'risks' => [],
                'alternatives_considered' => ['Synthetic verifier-only coverage was rejected.'],
                'decision' => 'Use the real orchestration and persistence services with test-only deterministic external adapters.',
            ],
            'implementation_plan' => [
                'steps' => ['Create the bounded fixture target.'],
                'files_to_create' => [$target],
                'files_to_modify' => [],
                'services' => [],
                'controllers' => [],
                'commands' => [],
                'entities' => [],
                'repositories' => [],
                'frontend_components' => [],
                'migrations' => [],
                'tests_required' => ['V0.1 acceptance integration'],
                'documentation_updates' => [],
                'completion_conditions' => ['AC-001 passes.'],
            ],
            'developer_handoff' => [
                'mandatory_constraints' => ['Mutate only the planned fixture target.'],
                'forbidden_changes' => [],
                'interfaces_to_respect' => [],
                'tests_required' => ['V0.1 acceptance integration'],
                'completion_conditions' => ['AC-001 passes.'],
            ],
            'documentation_changes' => [],
            'conditions' => [],
            'risks' => [],
            'unresolved_questions' => [],
            'required_human_decisions' => [],
        ];
    }

    private function developer(EngineeringAgentTask $task): array
    {
        $attempt = max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1));
        $path = 'symfony/src/Engineering/Acceptance/V01FixtureTarget.php';
        $repositoryFiles = is_array($task->inputs['repository_files'] ?? null) ? $task->inputs['repository_files'] : [];
        $exists = false;
        foreach ($repositoryFiles as $file) {
            if (is_array($file) && ($file['path'] ?? null) === $path) {
                $exists = true;
                break;
            }
        }
        $operation = $exists ? 'UPDATE' : 'CREATE';
        $content = "<?php\ndeclare(strict_types=1);\n\nnamespace App\\Engineering\\Acceptance;\n\nfinal class V01FixtureTarget\n{\n    public const REVISION = ".$attempt.";\n}\n";

        return [
            'status' => 'COMPLETED',
            'preflight' => ['status' => 'PASS', 'blockers' => [], 'architecture_conflicts' => []],
            'scope' => [
                'requested' => ['AC-001'],
                'implemented' => ['AC-001'],
                'not_implemented' => [],
            ],
            'repository_revision' => null,
            'changed_files' => [],
            'implementation_summary' => 'Implemented the bounded V0.1 acceptance fixture.',
            'database_changes' => [],
            'api_changes' => [],
            'acceptance_criteria_evidence' => [['id' => 'AC-001', 'evidence' => $path]],
            'tests_added' => [],
            'tests_run' => [],
            'validation' => [
                'commands_required' => ['CI', 'Runtime', 'Static Analysis'],
                'passed' => [],
                'failed' => [],
                'skipped' => [],
            ],
            'architecture_compliance' => ['adr_followed' => true, 'deviations' => []],
            'security' => ['checks_performed' => ['bounded path'], 'findings' => []],
            'known_limitations' => [],
            'deviations_from_plan' => [],
            'risks' => [],
            'findings' => [],
            'follow_up_required' => [],
            'changes' => [
                ['path' => $path, 'operation' => $operation, 'content' => $content],
            ],
            'commit_message' => 'test(engineering): V0.1 acceptance attempt '.$attempt,
            'pull_request_title' => 'Engineering V0.1 acceptance fixture',
            'pull_request_body' => 'Deterministic test-only acceptance PR. Human merge remains mandatory.',
        ];
    }

    private function review(EngineeringAgentTask $task, string $scenario): array
    {
        $attempt = max(1, (int) ($task->inputSnapshot['logical_attempt'] ?? 1));
        $reject = $scenario === 'fix-loop' && $attempt === 1;
        $revision = (string) ($task->inputSnapshot['repository_revision'] ?? 'fixture-revision');
        $pullRequest = (int) ($task->inputSnapshot['pull_request'] ?? ($task->inputs['development_result']['pull_request'] ?? 0));
        $issue = [
            'id' => 'REV-001',
            'severity' => 'MAJOR',
            'blocking' => true,
            'file' => 'symfony/src/Engineering/Acceptance/V01FixtureTarget.php',
            'line' => 7,
            'category' => 'CORRECTNESS',
            'problem' => 'The first deterministic implementation intentionally represents the injected defect.',
            'evidence' => 'Fixture review attempt 1.',
            'impact' => 'AC-001 must not be approved on the injected-defect revision.',
            'expected_fix' => 'Developer must produce the bounded correction revision.',
        ];

        $area = static fn (string $status = 'PASS'): array => ['status' => $status, 'findings' => []];

        return [
            'status' => $reject ? 'REQUEST_CHANGES' : 'APPROVED',
            'reviewed_revision' => $revision,
            'base_revision' => $revision,
            'pull_request' => $pullRequest,
            'preflight' => [
                'status' => 'PASS',
                'reviewed_revision' => $revision,
                'diff_complete' => true,
                'required_artifacts_present' => true,
                'ci_evidence_available' => true,
                'blockers' => [],
            ],
            'summary' => $reject ? 'Injected defect detected.' : 'Implementation approved.',
            'issues' => $reject ? [$issue] : [],
            'correctness' => $reject ? ['status' => 'FINDINGS', 'findings' => [$issue]] : $area(),
            'architecture' => ['compliant' => true, 'findings' => []],
            'security' => $area(),
            'maintainability' => $area(),
            'database' => ['status' => 'NOT_APPLICABLE', 'findings' => []],
            'api' => ['status' => 'NOT_APPLICABLE', 'findings' => []],
            'tests' => $area(),
            'acceptance_criteria' => [
                ['id' => 'AC-001', 'result' => $reject ? 'FAIL' : 'PASS', 'evidence' => $reject ? 'Injected defect.' : 'Reviewed exact fixture revision.'],
            ],
            'ci' => $this->ci(),
            'unresolved_blockers' => [],
            'unresolved_majors' => $reject ? [$issue] : [],
            'recommendation' => $reject ? 'REQUEST_CHANGES' : 'APPROVED',
        ];
    }

    private function qaExecution(EngineeringAgentTask $task): array
    {
        $revision = (string) ($task->inputSnapshot['repository_revision'] ?? '');
        $pullRequest = (int) ($task->inputSnapshot['pull_request'] ?? 0);

        $invariant = static fn (string $reason): array => [
            'applicable' => false,
            'status' => 'NOT_APPLICABLE',
            'evidence' => null,
            'reason' => $reason,
        ];
        $system = [];
        foreach ([
            'tenant_isolation','authorization','authentication','invalid_input','empty_state',
            'loading_state','error_state','api_error_handling','migration','rollback','backward_compatibility',
        ] as $name) {
            $system[$name] = $invariant('The bounded fixture does not change this system surface.');
        }

        return [
            'phase' => 'EXECUTION',
            'status' => 'PASS',
            'feature_id' => $task->featureId,
            'tested_revision' => $revision,
            'pull_request' => $pullRequest,
            'test_plan' => is_array($task->inputs['qa_test_plan'] ?? null) ? $task->inputs['qa_test_plan'] : $this->testPlan($task->featureId),
            'test_changes' => [],
            'tests' => [
                'total' => 5,
                'passed' => 5,
                'failed' => 0,
                'skipped' => 0,
                'suites' => [
                    'unit' => 'PASS',
                    'integration' => 'PASS',
                    'functional' => 'PASS',
                    'e2e' => 'PASS',
                    'smoke' => 'PASS',
                ],
            ],
            'acceptance_criteria' => [
                ['id' => 'AC-001', 'status' => 'PASS', 'evidence' => 'Exact reviewed revision passed deterministic acceptance.'],
            ],
            'system_invariants' => $system,
            'regressions' => [],
            'defects' => [],
            'security_findings' => [],
            'known_limitations' => [],
            'human_tests_required' => [],
            'blockers' => [],
            'repository_revision_after_tests' => null,
        ];
    }

    private function testPlan(string $featureId): array
    {
        return [
            'feature_id' => $featureId,
            'version' => 1,
            'scenarios' => [
                'positive' => ['Complete the bounded feature lifecycle.'],
                'negative' => ['Reject an injected defect in fix-loop scenario.'],
                'edge_cases' => ['Recover a stale RUNNING AgentRun.'],
            ],
            'permissions' => [],
            'tenant_cases' => [],
            'api_cases' => [],
            'database_cases' => [],
            'ui_cases' => [],
            'regression_cases' => ['No duplicate logical AgentRun after recovery.'],
            'performance_cases' => [],
            'required_suites' => [
                'unit' => true,
                'integration' => true,
                'functional' => true,
                'e2e' => true,
                'smoke' => true,
            ],
            'prerequisites' => [],
            'test_data' => [],
            'environment_requirements' => ['test-only deterministic external adapters'],
            'blocking_checks' => ['CI', 'Runtime', 'Static Analysis'],
        ];
    }

    private function ci(): array
    {
        $checks = [
            ['name' => 'CI', 'status' => 'completed', 'conclusion' => 'success'],
            ['name' => 'Runtime', 'status' => 'completed', 'conclusion' => 'success'],
            ['name' => 'Static Analysis', 'status' => 'completed', 'conclusion' => 'success'],
        ];

        return [
            'state' => 'SUCCESS',
            'total' => count($checks),
            'passed' => count($checks),
            'failed' => 0,
            'pending' => 0,
            'checks' => $checks,
        ];
    }

    private function scenario(EngineeringAgentTask $task): string
    {
        $encoded = strtolower((string) json_encode($task->inputs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (['fix-loop','human-gate','recovery','success'] as $scenario) {
            if (str_contains($encoded, '[scenario:'.$scenario.']')) return $scenario;
        }
        return 'success';
    }
}
