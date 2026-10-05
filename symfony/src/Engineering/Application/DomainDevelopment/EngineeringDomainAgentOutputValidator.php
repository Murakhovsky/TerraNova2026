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

        foreach ($output['release_checks'] as $check) {
            if (!is_array($check)) throw new RuntimeException('Domain release check must be an object.');
            if (($check['blocking'] ?? false) === true && ($check['status'] ?? null) !== 'PASS') {
                throw new RuntimeException('Domain RELEASE_READY requires all blocking release checks to PASS.');
            }
        }
        if (($output['required_human_decisions'] ?? []) !== []) {
            throw new RuntimeException('Domain RELEASE_READY cannot contain unresolved human decisions.');
        }
    }

}
