<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Acceptance\EngineeringV01AcceptanceVerifier;

$verifier = new EngineeringV01AcceptanceVerifier();
$featureId = 'feature-v01-acceptance';
$workflowId = 'workflow-v01-acceptance';

$artifact = static fn (array $content): array => [
    'id' => uniqid('artifact-', true),
    'type' => 'TEST',
    'version' => 1,
    'status' => 'ACTIVE',
    'content' => $content,
    'content_hash' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR)),
];

$baseStatus = static function () use ($featureId, $workflowId, $artifact): array {
    return [
        'feature' => [
            'id' => $featureId,
            'status' => 'READY_FOR_HUMAN_APPROVAL',
            'previous_context' => [],
        ],
        'workflow' => [
            'id' => $workflowId,
            'state' => 'READY_FOR_HUMAN_APPROVAL',
        ],
        'tasks' => [],
        'agent_runs' => [
            ['role' => 'ENGINEERING_MANAGER', 'status' => 'COMPLETED', 'idempotency_key' => 'manager-1', 'output' => ['status' => 'SPECIFICATION_READY']],
            ['role' => 'QA', 'status' => 'COMPLETED', 'idempotency_key' => 'qa-plan-1', 'output' => ['phase' => 'PLAN', 'status' => 'PLAN_READY']],
            ['role' => 'PRINCIPAL_ARCHITECT', 'status' => 'COMPLETED', 'idempotency_key' => 'architect-1', 'output' => ['status' => 'APPROVED']],
            ['role' => 'DEVELOPER', 'status' => 'COMPLETED', 'idempotency_key' => 'developer-1', 'output' => ['status' => 'COMPLETED', 'repository_revision' => 'rev-2', 'pull_request' => 184]],
            ['role' => 'REVIEWER', 'status' => 'COMPLETED', 'idempotency_key' => 'reviewer-1', 'output' => ['status' => 'APPROVED', 'reviewed_revision' => 'rev-2', 'pull_request' => 184]],
            ['role' => 'QA', 'status' => 'COMPLETED', 'idempotency_key' => 'qa-exec-1', 'output' => ['phase' => 'EXECUTION', 'status' => 'PASS', 'tested_revision' => 'rev-2', 'pull_request' => 184]],
        ],
        'artifacts' => [
            'FEATURE_SPEC' => $artifact(['acceptance_criteria' => [['id' => 'AC-001']]]),
            'CONTEXT_MAP' => $artifact(['affected_areas' => ['Engineering']]),
            'TEST_PLAN' => $artifact(['required_suites' => ['unit' => true]]),
            'ARCHITECTURE_DECISION' => $artifact(['status' => 'APPROVED', 'repository_revision' => 'rev-1']),
            'IMPLEMENTATION_PLAN' => $artifact(['files_to_modify' => ['symfony/src/Foo.php']]),
            'DEVELOPER_HANDOFF' => $artifact(['gate_status' => 'APPROVED']),
            'DEVELOPMENT_RESULT' => $artifact(['status' => 'COMPLETED', 'repository_revision' => 'rev-2', 'pull_request' => 184]),
            'REVIEW_REPORT' => $artifact(['status' => 'APPROVED', 'reviewed_revision' => 'rev-2', 'pull_request' => 184]),
            'QA_REPORT' => $artifact(['phase' => 'EXECUTION', 'status' => 'PASS', 'tested_revision' => 'rev-2', 'pull_request' => 184]),
            'FINAL_REPORT' => $artifact(['recommendation' => 'READY_FOR_HUMAN_APPROVAL', 'pull_request' => ['number' => 184]]),
        ],
        'findings' => [],
        'open_human_decisions' => [],
    ];
};

$baseAudit = static fn (): array => [
    ['workflow_id' => $workflowId, 'from' => 'NEW', 'to' => 'ANALYSIS'],
    ['workflow_id' => $workflowId, 'from' => 'ANALYSIS', 'to' => 'SPECIFICATION_READY'],
    ['workflow_id' => $workflowId, 'from' => 'SPECIFICATION_READY', 'to' => 'QA_PLANNING'],
    ['workflow_id' => $workflowId, 'from' => 'QA_PLANNING', 'to' => 'ARCHITECTURE_PENDING'],
    ['workflow_id' => $workflowId, 'from' => 'ARCHITECTURE_PENDING', 'to' => 'ARCHITECTURE_APPROVED'],
    ['workflow_id' => $workflowId, 'from' => 'ARCHITECTURE_APPROVED', 'to' => 'DEVELOPMENT_RUNNING'],
    ['workflow_id' => $workflowId, 'from' => 'DEVELOPMENT_RUNNING', 'to' => 'REVIEW_PENDING'],
    ['workflow_id' => $workflowId, 'from' => 'REVIEW_PENDING', 'to' => 'QA_PENDING'],
    ['workflow_id' => $workflowId, 'from' => 'QA_PENDING', 'to' => 'READY_FOR_HUMAN_APPROVAL'],
];

$success = $verifier->verify($featureId, 'success', $baseStatus(), $baseAudit(), []);
if (!$success['passed']) throw new RuntimeException('Success acceptance scenario failed: '.implode(', ', $success['failures']));

$fixStatus = $baseStatus();
array_splice($fixStatus['agent_runs'], 4, 1, [
    ['role' => 'REVIEWER', 'status' => 'COMPLETED', 'idempotency_key' => 'reviewer-reject-1', 'output' => ['status' => 'REQUEST_CHANGES', 'reviewed_revision' => 'rev-bad', 'pull_request' => 184]],
    ['role' => 'DEVELOPER', 'status' => 'COMPLETED', 'idempotency_key' => 'developer-2', 'output' => ['status' => 'COMPLETED', 'repository_revision' => 'rev-2', 'pull_request' => 184]],
    ['role' => 'REVIEWER', 'status' => 'COMPLETED', 'idempotency_key' => 'reviewer-2', 'output' => ['status' => 'APPROVED', 'reviewed_revision' => 'rev-2', 'pull_request' => 184]],
]);
$fixAudit = [
    ['workflow_id' => $workflowId, 'from' => 'REVIEW_PENDING', 'to' => 'CHANGES_REQUESTED'],
    ['workflow_id' => $workflowId, 'from' => 'CHANGES_REQUESTED', 'to' => 'DEVELOPMENT_RUNNING'],
    ['workflow_id' => $workflowId, 'from' => 'DEVELOPMENT_RUNNING', 'to' => 'REVIEW_PENDING'],
    ['workflow_id' => $workflowId, 'from' => 'REVIEW_PENDING', 'to' => 'QA_PENDING'],
    ['workflow_id' => $workflowId, 'from' => 'QA_PENDING', 'to' => 'READY_FOR_HUMAN_APPROVAL'],
];
$fixLoop = $verifier->verify($featureId, 'fix-loop', $fixStatus, $fixAudit, []);
if (!$fixLoop['passed']) throw new RuntimeException('Fix-loop acceptance scenario failed: '.implode(', ', $fixLoop['failures']));

$humanAudit = $baseAudit();
array_splice($humanAudit, 4, 0, [
    ['workflow_id' => $workflowId, 'from' => 'ARCHITECTURE_PENDING', 'to' => 'HUMAN_DECISION_REQUIRED'],
    ['workflow_id' => $workflowId, 'from' => 'HUMAN_DECISION_REQUIRED', 'to' => 'ARCHITECTURE_PENDING', 'human_decision_id' => 'decision-1'],
]);
$human = $verifier->verify($featureId, 'human-gate', $baseStatus(), $humanAudit, [
    ['id' => 'decision-1', 'status' => 'ANSWERED'],
]);
if (!$human['passed']) throw new RuntimeException('Human-gate acceptance scenario failed: '.implode(', ', $human['failures']));

$recoveryStatus = $baseStatus();
$recoveryStatus['feature']['previous_context'][] = [
    'engineering_recovery' => [
        'workflow_id' => $workflowId,
        'state' => 'REVIEW_PENDING',
        'correlation_id' => 'recovery-trace-1',
        'attempted_at' => '2026-10-05T10:00:00+03:00',
        'recovered_stale_runs' => 1,
    ],
];
$recovery = $verifier->verify($featureId, 'recovery', $recoveryStatus, $baseAudit(), []);
if (!$recovery['passed']) throw new RuntimeException('Recovery acceptance scenario failed: '.implode(', ', $recovery['failures']));

$missingRecovery = $verifier->verify($featureId, 'recovery', $baseStatus(), $baseAudit(), []);
if ($missingRecovery['passed'] || !in_array('recovery_continue_evidence', $missingRecovery['failures'], true)) {
    throw new RuntimeException('Recovery acceptance must fail without persisted continuation evidence.');
}

$duplicate = $baseStatus();
$duplicate['agent_runs'][5]['idempotency_key'] = 'developer-1';
$duplicateResult = $verifier->verify($featureId, 'success', $duplicate, $baseAudit(), []);
if ($duplicateResult['passed'] || !in_array('agent_run_idempotency_unique', $duplicateResult['failures'], true)) {
    throw new RuntimeException('Acceptance must reject duplicate AgentRun idempotency keys.');
}

echo "Engineering V0.1 acceptance verifier passed.\n";
