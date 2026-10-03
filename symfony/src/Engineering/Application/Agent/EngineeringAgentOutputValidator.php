<?php
declare(strict_types=1);

namespace App\Engineering\Application\Agent;

use App\Engineering\Domain\Agent\AgentRole;

final class EngineeringAgentOutputValidator
{
    public function validate(AgentRole $role, array $output): void
    {
        match ($role) {
            AgentRole::ENGINEERING_MANAGER => $this->manager($output),
            AgentRole::PRINCIPAL_ARCHITECT => $this->required($output, ['status','decision_summary','implementation_plan','risks','open_questions','required_human_decisions']),
            AgentRole::DEVELOPER => $this->developer($output),
            AgentRole::REVIEWER => $this->reviewer($output),
            AgentRole::QA => $this->qa($output),
        };
    }

    private function manager(array $output): void
    {
        $this->required($output, ['status','feature','context_map','tasks','risks','assumptions','open_questions','decision']);
        if (!is_array($output['feature'])) throw new EngineeringAgentOutputValidationException('Manager feature must be an object.');
        $this->required($output['feature'], [
            'title','type','business_goal','expected_behavior','scope','out_of_scope',
            'functional_requirements','acceptance_criteria','risks','assumptions','open_questions','priority','complexity',
        ]);
        if (trim((string) $output['feature']['business_goal']) === '') throw new EngineeringAgentOutputValidationException('Manager business goal cannot be empty.');
        if (!is_array($output['feature']['scope']) || $output['feature']['scope'] === []) throw new EngineeringAgentOutputValidationException('Manager scope cannot be empty.');
        if (!is_array($output['feature']['acceptance_criteria']) || $output['feature']['acceptance_criteria'] === []) throw new EngineeringAgentOutputValidationException('Manager acceptance criteria cannot be empty.');

        foreach ($output['feature']['acceptance_criteria'] as $index => $criterion) {
            if (!is_array($criterion)) throw new EngineeringAgentOutputValidationException('Acceptance criterion must be an object.');
            $this->required($criterion, ['id','description','verification_type']);
            if (!preg_match('/^AC-[0-9]{3,}$/', (string) $criterion['id'])) {
                throw new EngineeringAgentOutputValidationException(sprintf('Acceptance criterion %d has invalid id.', $index));
            }
            if (trim((string) $criterion['description']) === '') throw new EngineeringAgentOutputValidationException('Acceptance criterion description cannot be empty.');
        }

        if (($output['feature']['complexity'] ?? null) === 'XL' && (!is_array($output['tasks']) || count($output['tasks']) < 2)) {
            throw new EngineeringAgentOutputValidationException('XL feature must be decomposed into multiple tasks.');
        }
    }

    private function reviewer(array $output): void
    {
        $this->required($output, ['status','reviewed_revision','findings','acceptance_criteria','architecture_compliance','security_notes','recommendation']);
        if (($output['status'] ?? null) !== 'APPROVED') return;
        foreach ($output['findings'] as $finding) {
            if (is_array($finding) && strtolower((string) ($finding['severity'] ?? '')) === 'critical') {
                throw new EngineeringAgentOutputValidationException('Reviewer cannot APPROVE with a critical finding.');
            }
        }
    }

    private function developer(array $output): void
    {
        $this->required($output, ['status','changed_files','implementation_summary','tests_added','tests_run','known_limitations','findings','changes']);
        if (($output['status'] ?? null) !== 'COMPLETED') return;
        if (!is_array($output['changes'] ?? null) || $output['changes'] === []) {
            throw new EngineeringAgentOutputValidationException('Developer COMPLETED requires at least one repository change.');
        }
        foreach ($output['changes'] as $change) {
            if (!is_array($change)) throw new EngineeringAgentOutputValidationException('Developer change must be an object.');
            $this->required($change, ['path','operation']);
            $path = trim((string) $change['path']);
            $operation = (string) $change['operation'];
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\\0")) {
                throw new EngineeringAgentOutputValidationException('Developer change path is unsafe.');
            }
            if (!in_array($operation, ['CREATE','UPDATE','DELETE'], true)) {
                throw new EngineeringAgentOutputValidationException('Developer change operation is invalid.');
            }
            if ($operation !== 'DELETE' && !is_string($change['content'] ?? null)) {
                throw new EngineeringAgentOutputValidationException('Developer CREATE/UPDATE requires content.');
            }
        }
    }

    private function qa(array $output): void
    {
        $this->required($output, ['status','tested_revision','test_plan','acceptance_criteria','tests_total','tests_passed','tests_failed','defects','regressions','known_limitations']);
        if (($output['status'] ?? null) === 'PASS' && (int) ($output['tests_failed'] ?? 0) > 0) {
            throw new EngineeringAgentOutputValidationException('QA cannot PASS with failed tests.');
        }
        if ((int) ($output['tests_passed'] ?? 0) + (int) ($output['tests_failed'] ?? 0) > (int) ($output['tests_total'] ?? 0)) {
            throw new EngineeringAgentOutputValidationException('QA test totals are inconsistent.');
        }
        foreach ($output['acceptance_criteria'] as $criterion) {
            if (!is_array($criterion)) throw new EngineeringAgentOutputValidationException('QA acceptance criterion must be an object.');
            $this->required($criterion, ['id','result','evidence']);
            if (!in_array((string) $criterion['result'], ['PASS','FAIL','BLOCKED'], true)) {
                throw new EngineeringAgentOutputValidationException('QA acceptance criterion result is invalid.');
            }
        }
    }

    private function required(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $data)) {
                throw new EngineeringAgentOutputValidationException('Missing required agent output field: '.$field);
            }
        }
    }
}
