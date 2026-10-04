<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Agent\EngineeringAgentOutputValidationException;
use App\Engineering\Application\Agent\EngineeringAgentOutputValidator;
use App\Engineering\Domain\Agent\AgentRole;

$validator = new EngineeringAgentOutputValidator();

$valid = [
    'status' => 'SPECIFICATION_READY',
    'feature' => [
        'title' => 'Activity filters',
        'type' => 'FEATURE',
        'business_goal' => 'Users can filter activities.',
        'expected_behavior' => 'Actor/date filters are applied.',
        'scope' => ['Activity Center filtering'],
        'out_of_scope' => [],
        'functional_requirements' => [['id' => 'FR-001']],
        'acceptance_criteria' => [['id' => 'AC-001', 'description' => 'Actor filter returns only selected actor.', 'verification_type' => 'integration']],
        'risks' => [],
        'assumptions' => [],
        'open_questions' => [],
        'priority' => 'P2',
        'complexity' => 'S',
    ],
    'context_map' => [],
    'tasks' => [['id' => 'DEV-1']],
    'risks' => [],
    'assumptions' => [],
    'open_questions' => [],
    'decision' => ['type' => 'RUN_AGENT', 'agent' => 'principal_architect', 'reason' => 'mandatory'],
];
$validator->validate(AgentRole::ENGINEERING_MANAGER, $valid);

$noTasks = $valid;
$noTasks['tasks'] = [];
try {
    $validator->validate(AgentRole::ENGINEERING_MANAGER, $noTasks);
    throw new RuntimeException('Manager specification without engineering tasks was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$invalid = $valid;
$invalid['feature']['acceptance_criteria'] = [['id' => 'whatever', 'description' => '', 'verification_type' => 'manual']];
try {
    $validator->validate(AgentRole::ENGINEERING_MANAGER, $invalid);
    throw new RuntimeException('Invalid Manager acceptance criteria were accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architect = [
    'status' => 'APPROVED_WITH_CONDITIONS',
    'architecture_decision' => [
        'affected_domains' => ['engineering'],
        'primary_owner_domain' => 'engineering',
        'bounded_context' => 'Engineering',
        'current_architecture' => 'Existing orchestration runtime.',
        'proposed_solution' => 'Extend the existing runtime.',
        'components' => [],
        'interfaces' => [],
        'data_flow' => [],
        'dependencies' => [],
        'database_changes' => [],
        'api_changes' => [],
        'events' => [],
        'identity' => ['authentication_required' => true],
        'permissions' => ['repository.read'],
        'tenant_isolation' => ['scope' => 'SYSTEM'],
        'security' => ['repository content remains untrusted and writes stay policy-gated'],
        'migration_strategy' => null,
        'backward_compatibility' => ['classification' => 'NON_BREAKING'],
        'observability' => ['agent run, gate status and repository revision are persisted'],
        'testing_strategy' => ['unit','architecture'],
        'risks' => [],
        'alternatives_considered' => [],
        'decision' => 'Extend, do not duplicate.',
    ],
    'implementation_plan' => [
        'steps' => [['id' => 'ARCH-1']],
        'files_to_create' => [],
        'files_to_modify' => [],
        'services' => [],
        'controllers' => [],
        'commands' => [],
        'entities' => [],
        'repositories' => [],
        'frontend_components' => [],
        'migrations' => [],
        'tests_required' => ['unit'],
        'documentation_updates' => [],
        'completion_conditions' => ['Architecture gate enforced.'],
    ],
    'developer_handoff' => [
        'mandatory_constraints' => ['Use existing runtime.'],
        'forbidden_changes' => ['No merge/deploy.'],
        'interfaces_to_respect' => [],
        'tests_required' => ['unit'],
        'completion_conditions' => ['Gate passes.'],
    ],
    'documentation_changes' => [[
        'path' => 'docs/11-decisions/ADR-0099-example.md',
        'operation' => 'CREATE',
        'content' => '# ADR example',
    ]],
    'conditions' => ['No duplicate runtime.'],
    'risks' => [],
    'unresolved_questions' => [],
    'required_human_decisions' => [],
];
$validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architect);

$architectEnriched = $architect;
$architectEnriched['repository_state'] = ['context_revision' => 'abc', 'repository_revision' => 'def'];
$architectEnriched['architecture_decision']['feature_id'] = 'feature-1';
$architectEnriched['architecture_decision']['repository_revision'] = 'def';
$architectEnriched['implementation_plan']['feature_id'] = 'feature-1';
$architectEnriched['developer_handoff']['feature_id'] = 'feature-1';
$architectEnriched['developer_handoff']['gate_status'] = 'APPROVED_WITH_CONDITIONS';
$validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectEnriched);

$architectEmptyBoundedContext = $architect;
$architectEmptyBoundedContext['architecture_decision']['bounded_context'] = '';
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectEmptyBoundedContext);
    throw new RuntimeException('Architect approval without bounded context was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectMissingHandoffTests = $architect;
$architectMissingHandoffTests['developer_handoff']['tests_required'] = [];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectMissingHandoffTests);
    throw new RuntimeException('Architect approval without Developer Handoff tests was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectTooLarge = $architect;
$architectTooLarge['implementation_plan']['files_to_create'] = array_map(
    static fn (int $i): string => 'symfony/src/Generated/File'.$i.'.php',
    range(1, 20),
);
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectTooLarge);
    throw new RuntimeException('Architect approval exceeded the repository mutation budget.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectCreateModifyOverlap = $architect;
$architectCreateModifyOverlap['implementation_plan']['files_to_create'] = ['symfony/src/Shared/Example.php'];
$architectCreateModifyOverlap['implementation_plan']['files_to_modify'] = ['symfony/src/Shared/Example.php'];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectCreateModifyOverlap);
    throw new RuntimeException('Architect classified one path as both create and modify.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectDocOverlap = $architect;
$architectDocOverlap['implementation_plan']['files_to_modify'] = ['docs/11-decisions/ADR-0099-example.md'];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectDocOverlap);
    throw new RuntimeException('Architect allowed documentation and Developer path overlap.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectImplicitTenant = $architect;
$architectImplicitTenant['architecture_decision']['tenant_isolation'] = [];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectImplicitTenant);
    throw new RuntimeException('Architect approval without explicit tenant isolation was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectPlainApprovalWithConditions = $architect;
$architectPlainApprovalWithConditions['status'] = 'APPROVED';
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectPlainApprovalWithConditions);
    throw new RuntimeException('Architect APPROVED with mandatory conditions was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectMissingConditions = $architect;
$architectMissingConditions['conditions'] = [];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectMissingConditions);
    throw new RuntimeException('Conditional Architect approval without conditions was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectUnsafeDocs = $architect;
$architectUnsafeDocs['documentation_changes'][0]['path'] = 'symfony/src/Backdoor.php';
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectUnsafeDocs);
    throw new RuntimeException('Architect code mutation escaped docs boundary.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectHuman = $architect;
$architectHuman['status'] = 'NEEDS_HUMAN_DECISION';
$architectHuman['conditions'] = [];
$architectHuman['required_human_decisions'] = [];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectHuman);
    throw new RuntimeException('Architect human gate without a concrete decision was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectHuman['required_human_decisions'] = [[
    'question' => 'Create a new bounded context?',
    'reason' => 'This changes ownership.',
    'options' => [['id' => 'EXTEND'], ['id' => 'NEW_DOMAIN']],
    'recommended_option' => 'EXTEND',
]];
$validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectHuman);

$architectBadRecommendation = $architectHuman;
$architectBadRecommendation['required_human_decisions'][0]['recommended_option'] = 'NOT_OFFERED';
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectBadRecommendation);
    throw new RuntimeException('Architect recommended a human option that was not offered.');
} catch (EngineeringAgentOutputValidationException) {
}

$architectDuplicateOptions = $architectHuman;
$architectDuplicateOptions['required_human_decisions'][0]['options'] = [['id' => 'EXTEND'], ['id' => 'extend']];
try {
    $validator->validate(AgentRole::PRINCIPAL_ARCHITECT, $architectDuplicateOptions);
    throw new RuntimeException('Architect duplicate human decision options were accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$developer = [
    'status' => 'COMPLETED',
    'preflight' => ['status' => 'PASS', 'blockers' => [], 'architecture_conflicts' => []],
    'scope' => ['requested' => ['DEV-1'], 'implemented' => ['DEV-1'], 'not_implemented' => []],
    'repository_revision' => null,
    'changed_files' => [],
    'implementation_summary' => 'Implemented approved change.',
    'database_changes' => [],
    'api_changes' => [],
    'acceptance_criteria_evidence' => [['id' => 'AC-001', 'result' => 'PASS']],
    'tests_added' => ['tests/unit/example.php'],
    'tests_run' => [],
    'validation' => ['commands_required' => ['php tests/unit/example.php'], 'passed' => [], 'failed' => [], 'skipped' => []],
    'architecture_compliance' => ['adr_followed' => true, 'deviations' => []],
    'security' => ['checks_performed' => [], 'findings' => []],
    'known_limitations' => [],
    'deviations_from_plan' => [],
    'risks' => [],
    'findings' => [],
    'follow_up_required' => [],
    'changes' => [[
        'path' => 'symfony/src/Example.php',
        'operation' => 'CREATE',
        'content' => "<?php\ndeclare(strict_types=1);\n",
    ]],
];
$validator->validate(AgentRole::DEVELOPER, $developer);

$developerFailedValidation = $developer;
$developerFailedValidation['validation']['failed'] = ['phpstan'];
try {
    $validator->validate(AgentRole::DEVELOPER, $developerFailedValidation);
    throw new RuntimeException('Developer completion with failed validation was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$developerSilentArchitectureChange = $developer;
$developerSilentArchitectureChange['architecture_compliance']['adr_followed'] = false;
try {
    $validator->validate(AgentRole::DEVELOPER, $developerSilentArchitectureChange);
    throw new RuntimeException('Developer completion with ADR deviation was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$developerNeedsArchitecture = $developer;
$developerNeedsArchitecture['status'] = 'ARCHITECTURE_REVIEW_REQUIRED';
$developerNeedsArchitecture['preflight']['status'] = 'BLOCKED';
$developerNeedsArchitecture['preflight']['architecture_conflicts'] = ['Approved file no longer exists.'];
$developerNeedsArchitecture['changes'] = [];
$validator->validate(AgentRole::DEVELOPER, $developerNeedsArchitecture);

$developerNeedsArchitectureWithMutation = $developerNeedsArchitecture;
$developerNeedsArchitectureWithMutation['changes'] = $developer['changes'];
try {
    $validator->validate(AgentRole::DEVELOPER, $developerNeedsArchitectureWithMutation);
    throw new RuntimeException('Developer architecture escalation with mutations was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$developerWithLimitations = $developer;
$developerWithLimitations['status'] = 'COMPLETED_WITH_LIMITATIONS';
$developerWithLimitations['known_limitations'] = ['CI execution evidence is deferred to QA.'];
$validator->validate(AgentRole::DEVELOPER, $developerWithLimitations);

$reviewer = [
    'status' => 'APPROVED',
    'reviewed_revision' => 'abc123',
    'base_revision' => 'base123',
    'pull_request' => 42,
    'preflight' => ['status' => 'PASS', 'reviewed_revision' => 'abc123', 'diff_complete' => true, 'required_artifacts_present' => true, 'ci_evidence_available' => true, 'blockers' => []],
    'summary' => 'Independent review passed.',
    'issues' => [[
        'id' => 'REV-001', 'severity' => 'SUGGESTION', 'blocking' => false, 'file' => 'symfony/src/Example.php', 'line' => 10, 'category' => 'MAINTAINABILITY',
        'problem' => 'A name could be clearer.', 'evidence' => 'Local variable name is generic.', 'impact' => 'Minor readability cost.', 'expected_fix' => 'Consider a clearer name in a future cleanup.',
    ]],
    'correctness' => ['status' => 'PASS', 'findings' => []],
    'architecture' => ['compliant' => true, 'findings' => []],
    'security' => ['status' => 'PASS', 'findings' => []],
    'maintainability' => ['status' => 'FINDINGS', 'findings' => ['REV-001']],
    'database' => ['status' => 'NOT_APPLICABLE', 'findings' => []],
    'api' => ['status' => 'NOT_APPLICABLE', 'findings' => []],
    'tests' => ['status' => 'PASS', 'findings' => []],
    'acceptance_criteria' => [['id' => 'AC-001', 'result' => 'PASS', 'evidence' => 'Diff and tests cover behavior.']],
    'ci' => ['state' => 'PASSED', 'total' => 2, 'passed' => 2, 'failed' => 0, 'pending' => 0, 'checks' => []],
    'unresolved_blockers' => [], 'unresolved_majors' => [], 'recommendation' => 'Proceed to QA.',
];
$validator->validate(AgentRole::REVIEWER, $reviewer);

$reviewerBlockerApproved = $reviewer;
$reviewerBlockerApproved['issues'][0] = ['id' => 'REV-002','severity' => 'BLOCKER','blocking' => true,'file' => 'symfony/src/Example.php','line' => 12,'category' => 'SECURITY','problem' => 'Tenant boundary is missing.','evidence' => 'Query has no tenant predicate.','impact' => 'Cross-tenant data exposure.','expected_fix' => 'Add tenant-scoped repository constraint and regression test.'];
try { $validator->validate(AgentRole::REVIEWER, $reviewerBlockerApproved); throw new RuntimeException('Reviewer APPROVED with BLOCKER was accepted.'); } catch (EngineeringAgentOutputValidationException) {}

$reviewerChanges = $reviewerBlockerApproved;
$reviewerChanges['status'] = 'REQUEST_CHANGES';
$reviewerChanges['unresolved_blockers'] = ['REV-002'];
$validator->validate(AgentRole::REVIEWER, $reviewerChanges);

$reviewerArchitecture = $reviewerChanges;
$reviewerArchitecture['status'] = 'ARCHITECTURE_REVIEW_REQUIRED';
$reviewerArchitecture['architecture'] = ['compliant' => false, 'findings' => ['REV-003']];
$reviewerArchitecture['issues'][0] = ['id' => 'REV-003','severity' => 'MAJOR','blocking' => true,'file' => null,'line' => null,'category' => 'ARCHITECTURE','problem' => 'Approved plan conflicts with bounded-context ownership.','evidence' => 'Implementation requires a dependency forbidden by the ADR.','impact' => 'Proceeding would create architecture drift.','expected_fix' => 'Principal Architect must revalidate the plan; Reviewer must not redesign it.'];
$reviewerArchitecture['unresolved_blockers'] = []; $reviewerArchitecture['unresolved_majors'] = ['REV-003'];
$validator->validate(AgentRole::REVIEWER, $reviewerArchitecture);

$reviewerHuman = $reviewer;
$reviewerHuman['status'] = 'HUMAN_REVIEW_REQUIRED';
$reviewerHuman['preflight']['status'] = 'BLOCKED';
$reviewerHuman['human_review'] = ['reason' => 'Acceptance criterion conflicts with a destructive migration requirement.', 'decision_required' => 'Choose whether data loss is acceptable.'];
$validator->validate(AgentRole::REVIEWER, $reviewerHuman);

$reviewerFailedCi = $reviewer; $reviewerFailedCi['ci']['state'] = 'FAILED'; $reviewerFailedCi['ci']['failed'] = 1;
try { $validator->validate(AgentRole::REVIEWER, $reviewerFailedCi); throw new RuntimeException('Reviewer APPROVED with failed CI was accepted.'); } catch (EngineeringAgentOutputValidationException) {}

$qaTestPlan = [
    'feature_id' => 'feature-1',
    'version' => '1',
    'scenarios' => ['positive' => ['happy path'], 'negative' => ['invalid input'], 'edge_cases' => ['empty state']],
    'permissions' => ['unauthorized denied'],
    'tenant_cases' => ['tenant A cannot access tenant B'],
    'api_cases' => ['documented response contract'],
    'database_cases' => [],
    'ui_cases' => [],
    'regression_cases' => ['critical smoke'],
    'performance_cases' => [],
    'required_suites' => ['unit' => true, 'integration' => true, 'functional' => true, 'e2e' => false, 'smoke' => true],
    'prerequisites' => [],
    'test_data' => [],
    'environment_requirements' => [],
    'blocking_checks' => ['AC-001'],
];

$qaPlan = [
    'phase' => 'PLAN',
    'status' => 'PLAN_READY',
    'feature_id' => 'feature-1',
    'tested_revision' => null,
    'pull_request' => null,
    'test_plan' => $qaTestPlan,
    'test_changes' => [],
];
$validator->validate(AgentRole::QA, $qaPlan);

$notApplicable = ['applicable' => false, 'status' => 'NOT_APPLICABLE', 'evidence' => null, 'reason' => 'Not part of this feature surface.'];
$applicablePass = ['applicable' => true, 'status' => 'PASS', 'evidence' => ['type' => 'TEST_RESULT', 'reference' => 'qa-1'], 'reason' => null];
$qaPass = [
    'phase' => 'EXECUTION',
    'status' => 'PASS',
    'feature_id' => 'feature-1',
    'tested_revision' => 'abc123',
    'pull_request' => 42,
    'test_plan' => $qaTestPlan,
    'test_changes' => [],
    'tests' => ['total' => 3, 'passed' => 3, 'failed' => 0, 'skipped' => 0, 'suites' => ['unit' => 'PASS', 'integration' => 'PASS', 'smoke' => 'PASS']],
    'acceptance_criteria' => [['id' => 'AC-001', 'status' => 'PASS', 'evidence' => ['type' => 'HTTP_RESPONSE', 'status' => 200]]],
    'system_invariants' => [
        'tenant_isolation' => $applicablePass,
        'authorization' => $applicablePass,
        'authentication' => $notApplicable,
        'invalid_input' => $applicablePass,
        'empty_state' => $applicablePass,
        'loading_state' => $notApplicable,
        'error_state' => $applicablePass,
        'api_error_handling' => $applicablePass,
        'migration' => $notApplicable,
        'rollback' => $notApplicable,
        'backward_compatibility' => $applicablePass,
    ],
    'regressions' => [],
    'defects' => [],
    'security_findings' => [],
    'known_limitations' => [],
    'human_tests_required' => [],
    'blockers' => [],
    'repository_revision_after_tests' => null,
];
$validator->validate(AgentRole::QA, $qaPass);

$qaFailedTests = $qaPass;
$qaFailedTests['tests']['passed'] = 2;
$qaFailedTests['tests']['failed'] = 1;
try {
    $validator->validate(AgentRole::QA, $qaFailedTests);
    throw new RuntimeException('QA PASS with failed tests was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

$qaUnsafeTestMutation = $qaPass;
$qaUnsafeTestMutation['status'] = 'TESTS_UPDATED';
$qaUnsafeTestMutation['test_changes'] = [['path' => 'symfony/src/Backdoor.php', 'operation' => 'UPDATE', 'content' => '<?php']];
try {
    $validator->validate(AgentRole::QA, $qaUnsafeTestMutation);
    throw new RuntimeException('QA production mutation escaped test roots.');
} catch (EngineeringAgentOutputValidationException) {
}

$qaTestsUpdated = $qaPass;
$qaTestsUpdated['status'] = 'TESTS_UPDATED';
$qaTestsUpdated['test_changes'] = [['path' => 'tests/unit/feature_regression.php', 'operation' => 'CREATE', 'content' => '<?php']];
$validator->validate(AgentRole::QA, $qaTestsUpdated);

$qaHuman = $qaPass;
$qaHuman['status'] = 'HUMAN_TEST_REQUIRED';
$qaHuman['human_tests_required'] = [['scenario' => 'Visual focus order', 'required_evidence' => 'Manual UI observation']];
$validator->validate(AgentRole::QA, $qaHuman);

$qaFail = $qaPass;
$qaFail['status'] = 'FAIL';
$qaFail['tests'] = ['total' => 1, 'passed' => 0, 'failed' => 1, 'skipped' => 0, 'suites' => ['functional' => 'FAIL']];
$qaFail['acceptance_criteria'] = [['id' => 'AC-001', 'status' => 'FAIL', 'evidence' => ['type' => 'HTTP_RESPONSE', 'status' => 500]]];
$qaFail['defects'] = [['severity' => 'MAJOR', 'title' => 'Request fails', 'description' => 'Expected success but received server error.']];
$validator->validate(AgentRole::QA, $qaFail);

echo "Engineering agent output validation passed.\n";
