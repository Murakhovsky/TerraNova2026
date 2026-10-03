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

$reviewerNonCompliantApproval = [
    'status' => 'APPROVED',
    'reviewed_revision' => 'abc123',
    'findings' => [],
    'acceptance_criteria' => [],
    'architecture_compliance' => false,
    'security_notes' => [],
    'recommendation' => 'continue',
];
try {
    $validator->validate(AgentRole::REVIEWER, $reviewerNonCompliantApproval);
    throw new RuntimeException('Reviewer approved architecture-noncompliant implementation.');
} catch (EngineeringAgentOutputValidationException) {
}

$qa = [
    'status' => 'PASS', 'tested_revision' => 'abc', 'acceptance_criteria' => [],
    'tests_total' => 2, 'tests_passed' => 1, 'tests_failed' => 1,
    'defects' => [], 'regressions' => [], 'known_limitations' => [],
];
try {
    $validator->validate(AgentRole::QA, $qa);
    throw new RuntimeException('QA PASS with failed tests was accepted.');
} catch (EngineeringAgentOutputValidationException) {
}

echo "Engineering agent output validation passed.\n";
