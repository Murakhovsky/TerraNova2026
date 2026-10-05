<?php
declare(strict_types=1);

namespace App\Engineering\Application\DomainDevelopment;

use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\DomainDevelopment\FeatureDependencyGraph;
use RuntimeException;

final readonly class EngineeringDomainAgentOutputValidator
{
    public function __construct(private FeatureDependencyGraph $graph = new FeatureDependencyGraph()) {}

    /** @param array<string,mixed> $output */
    public function validate(AgentRole $role, array $output, ?string $phase = null): void
    {
        match ($role) {
            AgentRole::ENGINEERING_MANAGER,
            AgentRole::PRODUCT_REQUIREMENTS => $this->manager($output),
            AgentRole::QA_PLANNER,
            AgentRole::QA_EXECUTOR,
            AgentRole::QA => $this->qa($output, $phase),
            AgentRole::PRINCIPAL_ARCHITECT => $this->architect($output),
            AgentRole::INTEGRATION_RELEASE => $this->integrationRelease($output),
            default => throw new RuntimeException('Unsupported Domain Development validation role: '.$role->value),
        };
    }

    /** @param array<string,mixed> $output */
    private function manager(array $output): void
    {
        $this->required($output, ['status','domain_specification','domain_acceptance_criteria','capabilities','risks','open_questions']);
        $status = (string) $output['status'];
        if (!in_array($status, ['SPECIFICATION_READY','HUMAN_DECISION_REQUIRED','BLOCKED','FAILED'], true)) {
            throw new RuntimeException('Domain Manager status is invalid.');
        }
        if ($status !== 'SPECIFICATION_READY') return;
        if (!is_array($output['domain_specification']) || $output['domain_specification'] === []) throw new RuntimeException('Domain Manager requires Domain Specification.');
        if (!is_array($output['domain_acceptance_criteria']) || $output['domain_acceptance_criteria'] === []) throw new RuntimeException('Domain Manager requires Domain Acceptance Criteria.');
        if (!is_array($output['capabilities']) || $output['capabilities'] === []) throw new RuntimeException('Domain Manager requires capability map.');
        $ids = [];
        foreach ($output['domain_acceptance_criteria'] as $criterion) {
            if (!is_array($criterion)) throw new RuntimeException('Domain Acceptance Criterion must be an object.');
            $id = strtoupper(trim((string) ($criterion['id'] ?? '')));
            if ($id === '') throw new RuntimeException('Domain Acceptance Criterion requires id.');
            if (isset($ids[$id])) throw new RuntimeException('Duplicate Domain Acceptance Criterion: '.$id);
            $ids[$id] = true;
        }
        foreach ($output['capabilities'] as $capability) {
            if (!is_array($capability)) throw new RuntimeException('Domain capability must be an object.');
            foreach (['key','name','description','kind','required','depends_on','acceptance_criteria'] as $field) {
                if (!array_key_exists($field, $capability)) throw new RuntimeException('Domain capability missing '.$field.'.');
            }
            $capabilityKey = trim((string) $capability['key']);
            if ($capabilityKey === '') throw new RuntimeException('Domain capability key is required.');
            if (!is_array($capability['acceptance_criteria']) || $capability['acceptance_criteria'] === []) {
                throw new RuntimeException('Domain capability '.$capabilityKey.' requires acceptance criteria.');
            }
            foreach ($capability['acceptance_criteria'] as $criterionId) {
                $criterionId = strtoupper(trim((string) $criterionId));
                if (!isset($ids[$criterionId])) {
                    throw new RuntimeException('Capability '.$capabilityKey.' references unknown Domain Acceptance Criterion '.$criterionId.'.');
                }
            }
        }
    }

    /** @param array<string,mixed> $output */
    private function architect(array $output): void
    {
        $this->required($output, [
            'status','domain_architecture','architecture_constitution','capabilities','features','dependencies','contracts','events',
            'parallelization_groups','critical_path','migration_plan','integration_strategy','release_strategy','required_human_decisions',
        ]);
        $status = (string) $output['status'];
        if (!in_array($status, ['APPROVED','APPROVED_WITH_CONDITIONS','REJECTED','NEEDS_HUMAN_DECISION'], true)) {
            throw new RuntimeException('Domain Architect status is invalid.');
        }
        if (!in_array($status, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) return;
        if (!is_array($output['domain_architecture']) || $output['domain_architecture'] === []) throw new RuntimeException('Approved Domain Architecture cannot be empty.');
        $featureFlags = $output['domain_architecture']['feature_flags'] ?? null;
        if (!is_array($featureFlags) || array_is_list($featureFlags)) throw new RuntimeException('Approved Domain Architecture requires structured feature flags.');
        foreach (['DOMAIN_ENABLED','FEATURE_ENABLED','INTEGRATION_ENABLED','PRODUCTION_EXECUTION_ENABLED'] as $flag) {
            if (!array_key_exists($flag, $featureFlags)) throw new RuntimeException('Domain feature flags missing '.$flag.'.');
        }
        if (!is_bool($featureFlags['DOMAIN_ENABLED']) || !is_bool($featureFlags['INTEGRATION_ENABLED']) || !is_bool($featureFlags['PRODUCTION_EXECUTION_ENABLED'])) {
            throw new RuntimeException('Domain enable/integration/production flags must be boolean.');
        }
        if (!is_array($featureFlags['FEATURE_ENABLED']) || array_is_list($featureFlags['FEATURE_ENABLED'])) {
            throw new RuntimeException('FEATURE_ENABLED must be a feature-key boolean map.');
        }
        foreach ($featureFlags['FEATURE_ENABLED'] as $key => $enabled) {
            if (!is_string($key) || trim($key) === '' || !is_bool($enabled)) throw new RuntimeException('FEATURE_ENABLED entries must be feature-key booleans.');
        }
        if (!is_array($output['architecture_constitution']) || ($output['architecture_constitution']['rules'] ?? []) === []) throw new RuntimeException('Approved Domain Architecture requires Constitution rules.');
        if (!is_array($output['features']) || $output['features'] === []) throw new RuntimeException('Approved Domain Architecture requires feature decomposition.');
        if (!is_array($output['dependencies'])) throw new RuntimeException('Domain dependencies must be an array.');

        $this->graph->assertValid($output['features'], $output['dependencies']);

        $featureKeys = [];
        foreach ($output['features'] as $feature) {
            if (!is_array($feature)) throw new RuntimeException('Domain feature must be an object.');
            foreach (['key','capability_key','title','kind','priority','risk','acceptance_criteria','owned_paths','shared_paths','forbidden_paths'] as $field) {
                if (!array_key_exists($field, $feature)) throw new RuntimeException('Domain feature missing '.$field.'.');
            }
            $key = trim((string) $feature['key']);
            if ($key === '') throw new RuntimeException('Domain feature key is required.');
            $featureKeys[$key] = true;
            foreach (['owned_paths','shared_paths','forbidden_paths'] as $list) {
                if (!is_array($feature[$list])) throw new RuntimeException('Domain feature '.$key.' '.$list.' must be an array.');
                foreach ($feature[$list] as $path) $this->assertPath((string) $path);
            }
        }

        $migrationPlan = $output['migration_plan'] ?? null;
        if (!is_array($migrationPlan) || array_is_list($migrationPlan)) {
            throw new RuntimeException('Approved Domain Architecture requires structured MigrationPlan.');
        }
        foreach (['migration_order','dependencies','forward_validation','rollback_strategy','data_migration','compatibility_window','risk','requires_downtime','destructive'] as $field) {
            if (!array_key_exists($field, $migrationPlan)) throw new RuntimeException('MigrationPlan missing '.$field.'.');
        }
        if (!is_array($migrationPlan['migration_order'])) throw new RuntimeException('MigrationPlan migration_order must be an array.');
        if (!in_array(strtoupper((string) $migrationPlan['risk']), ['LOW','MEDIUM','HIGH','CRITICAL'], true)) {
            throw new RuntimeException('MigrationPlan risk is invalid.');
        }
        if (!is_bool($migrationPlan['requires_downtime']) || !is_bool($migrationPlan['destructive'])) {
            throw new RuntimeException('MigrationPlan downtime/destructive flags must be boolean.');
        }

        foreach (is_array($output['contracts'] ?? null) ? $output['contracts'] : [] as $contract) {
            if (!is_array($contract)) throw new RuntimeException('Domain contract must be an object.');
            foreach (['id','name','version','type','owner_domain','producer','consumers','schema','compatibility','status'] as $field) {
                if (!array_key_exists($field, $contract)) throw new RuntimeException('Domain contract missing '.$field.'.');
            }
            if (!in_array(strtoupper((string) $contract['type']), [
                'DOMAIN_INTERFACE','APPLICATION_INTERFACE','API_CONTRACT','EVENT_CONTRACT','DATABASE_CONTRACT','INTEGRATION_CONTRACT','PERMISSION_CONTRACT',
            ], true)) throw new RuntimeException('Domain contract type is invalid.');
            if (!in_array(strtoupper((string) $contract['compatibility']), ['BACKWARD_COMPATIBLE','BREAKING','DEPRECATED'], true)) {
                throw new RuntimeException('Domain contract compatibility is invalid.');
            }
            if (!is_array($contract['consumers']) || !is_array($contract['schema'])) {
                throw new RuntimeException('Domain contract consumers/schema must be structured.');
            }
        }

        foreach (is_array($output['events'] ?? null) ? $output['events'] : [] as $event) {
            if (!is_array($event)) throw new RuntimeException('Domain event must be an object.');
            foreach (['name','version','producer','consumers','payload_schema','delivery','idempotency','ordering'] as $field) {
                if (!array_key_exists($field, $event)) throw new RuntimeException('Domain event missing '.$field.'.');
            }
            if (trim((string) $event['producer']) === '' || trim((string) $event['idempotency']) === '' || trim((string) $event['ordering']) === '') {
                throw new RuntimeException('Domain event requires producer, idempotency and ordering contracts.');
            }
            if (!is_array($event['consumers']) || !is_array($event['payload_schema'])) {
                throw new RuntimeException('Domain event consumers/payload_schema must be structured.');
            }
        }

        $owners = [];
        foreach ($output['features'] as $feature) {
            foreach (is_array($feature['owned_paths'] ?? null) ? $feature['owned_paths'] : [] as $path) {
                $path = rtrim((string) $path, '/');
                if ($path === '') continue;
                if (isset($owners[$path]) && $owners[$path] !== $feature['key']) {
                    throw new RuntimeException('Two Domain features own the same path: '.$path);
                }
                $owners[$path] = (string) $feature['key'];
            }
        }
    }

    /** @param array<string,mixed> $output */
    private function qa(array $output, ?string $phase): void
    {
        $this->required($output, ['status','domain_qa_plan','blockers','human_tests_required']);
        $status = (string) $output['status'];
        if (!in_array($status, ['PLAN_READY','PASS','FAIL','BLOCKED','HUMAN_TEST_REQUIRED'], true)) throw new RuntimeException('Domain QA status is invalid.');
        if (!is_array($output['domain_qa_plan'])) throw new RuntimeException('Domain QA Plan must be an object.');

        if ($phase === 'PLAN') {
            if (!in_array($status, ['PLAN_READY','BLOCKED','HUMAN_TEST_REQUIRED'], true)) throw new RuntimeException('Domain QA planning returned execution status.');
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['release_blocking_checks'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires release-blocking checks.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['cross_feature_workflows'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires explicit cross-feature workflow coverage.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['regression'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires explicit regression coverage.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['architecture_tests'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires explicit architecture-test coverage.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['contract_cases'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires explicit contract-test coverage or an evidence-backed N/A contract case.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['migration_cases'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires migration validation coverage.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['security'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires security-check coverage.');
            }
            if ($status === 'PLAN_READY' && ($output['domain_qa_plan']['smoke'] ?? []) === []) {
                throw new RuntimeException('Domain QA Plan requires a curated critical smoke suite.');
            }
            $isolation = $output['domain_qa_plan']['domain_isolation'] ?? null;
            if ($status === 'PLAN_READY') {
                if (!is_array($isolation) || array_is_list($isolation)) {
                    throw new RuntimeException('Domain QA Plan requires structured Domain isolation validation.');
                }
                foreach (['namespace_boundaries','database_boundaries','infrastructure_imports','cross_domain_access','module_ownership','public_private_services'] as $check) {
                    if (!array_key_exists($check, $isolation) || !is_array($isolation[$check])) {
                        throw new RuntimeException('Domain isolation plan missing '.$check.'.');
                    }
                }
            }
            return;
        }

        if ($phase === 'EXECUTION') {
            if ($status === 'PLAN_READY') throw new RuntimeException('Domain QA execution cannot return PLAN_READY.');
            if ($status === 'PASS') {
                $criteria = $output['acceptance_criteria'] ?? null;
                if (!is_array($criteria) || $criteria === []) throw new RuntimeException('Domain QA PASS requires Domain Acceptance Criteria evidence.');
                foreach ($criteria as $criterion) {
                    if (!is_array($criterion) || ($criterion['status'] ?? null) !== 'PASS' || !$this->evidence($criterion['evidence'] ?? null)) {
                        throw new RuntimeException('Domain QA PASS requires evidence for every Domain Acceptance Criterion.');
                    }
                }
                if (($output['defects'] ?? []) !== []) {
                    foreach ($output['defects'] as $defect) {
                        if (is_array($defect) && in_array(strtoupper((string) ($defect['severity'] ?? '')), ['BLOCKER','MAJOR','CRITICAL','HIGH'], true)) {
                            throw new RuntimeException('Domain QA cannot PASS with blocking defects.');
                        }
                    }
                }
            }
            if ($status === 'FAIL' && ($output['defects'] ?? []) === [] && ($output['acceptance_criteria'] ?? []) === []) throw new RuntimeException('Domain QA FAIL requires failure evidence.');
            if ($status === 'BLOCKED' && ($output['blockers'] ?? []) === []) throw new RuntimeException('Domain QA BLOCKED requires blockers.');
            if ($status === 'HUMAN_TEST_REQUIRED' && ($output['human_tests_required'] ?? []) === []) throw new RuntimeException('Domain QA HUMAN_TEST_REQUIRED requires manual scenarios.');
        }
    }

    /** @param array<string,mixed> $data @param list<string> $fields */
    private function required(array $data, array $fields): void
    {
        foreach ($fields as $field) if (!array_key_exists($field, $data)) throw new RuntimeException('Domain agent output missing '.$field.'.');
    }

    private function assertPath(string $path): void
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('Unsafe Domain feature path: '.$path);
        }
    }

    private function evidence(mixed $value): bool
    {
        if (is_string($value)) return trim($value) !== '';
        if (is_array($value)) return $value !== [];
        return is_scalar($value) && $value !== null;
    }

    /** @param array<string,mixed> $output */
    private function integrationRelease(array $output): void
    {
        $this->required($output, ['status','integration_summary','release_checks','known_limitations','required_human_decisions']);
        $status = (string) ($output['status'] ?? '');
        if (!in_array($status, ['RELEASE_READY','BLOCKED','HUMAN_DECISION_REQUIRED','FAILED'], true)) {
            throw new RuntimeException('Domain Integration & Release status is invalid.');
        }
        if (!is_array($output['release_checks'] ?? null)) {
            throw new RuntimeException('Domain Integration & Release checks must be an array.');
        }
        if ($status !== 'RELEASE_READY') return;

        $requiredChecks = [
            'DOMAIN_ARCHITECTURE',
            'DOMAIN_ACCEPTANCE_CRITERIA',
            'DOMAIN_QA',
            'ARCHITECTURE_TESTS',
            'CONTRACT_TESTS',
            'MIGRATION_PLAN',
            'SECURITY_CHECKS',
            'CRITICAL_SMOKE',
            'DOCUMENTATION',
            'CI',
        ];
        $checksById = [];
        foreach ($output['release_checks'] as $check) {
            if (!is_array($check)) throw new RuntimeException('Domain release check must be an object.');
            $id = strtoupper(trim((string) ($check['id'] ?? '')));
            if ($id === '') throw new RuntimeException('Domain release check requires id.');
            if (isset($checksById[$id])) throw new RuntimeException('Duplicate Domain release check '.$id.'.');
            $checksById[$id] = $check;
            if (($check['blocking'] ?? false) === true && ($check['status'] ?? null) !== 'PASS') {
                throw new RuntimeException('Domain RELEASE_READY requires all blocking release checks to PASS.');
            }
        }
        foreach ($requiredChecks as $id) {
            $check = $checksById[$id] ?? null;
            if (!is_array($check)) throw new RuntimeException('Domain RELEASE_READY missing required check '.$id.'.');
            if (($check['blocking'] ?? null) !== true || ($check['status'] ?? null) !== 'PASS' || !$this->evidence($check['evidence'] ?? null)) {
                throw new RuntimeException('Domain RELEASE_READY requires blocking PASS evidence for '.$id.'.');
            }
        }
        if (($output['required_human_decisions'] ?? []) !== []) {
            throw new RuntimeException('Domain RELEASE_READY cannot contain unresolved human decisions.');
        }
    }

}
