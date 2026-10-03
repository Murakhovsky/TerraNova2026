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
            AgentRole::PRINCIPAL_ARCHITECT => $this->architect($output),
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
        if (!is_array($output['tasks']) || $output['tasks'] === []) throw new EngineeringAgentOutputValidationException('Manager must create at least one engineering task.');

        foreach ($output['tasks'] as $index => $task) {
            if (!is_array($task)) throw new EngineeringAgentOutputValidationException('Engineering task must be an object.');
            if (trim((string) ($task['id'] ?? '')) === '') {
                throw new EngineeringAgentOutputValidationException(sprintf('Engineering task %d requires a stable id.', $index));
            }
            if (isset($task['assigned_role']) && !in_array(strtoupper((string) $task['assigned_role']), ['PRINCIPAL_ARCHITECT','DEVELOPER','REVIEWER','QA'], true)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Engineering task %d has an invalid assigned role.', $index));
            }
        }

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

    private function architect(array $output): void
    {
        $this->required($output, ['status','architecture_decision','implementation_plan','developer_handoff','documentation_changes','conditions','risks','unresolved_questions','required_human_decisions']);
        $status = (string) $output['status'];
        if (!in_array($status, ['APPROVED','APPROVED_WITH_CONDITIONS','REJECTED','NEEDS_HUMAN_DECISION'], true)) {
            throw new EngineeringAgentOutputValidationException('Architect gate status is invalid.');
        }
        foreach (['architecture_decision','implementation_plan','developer_handoff'] as $section) {
            if (!is_array($output[$section])) throw new EngineeringAgentOutputValidationException('Architect '.$section.' must be an object.');
        }
        if (array_key_exists('repository_state', $output) && !is_array($output['repository_state'])) {
            throw new EngineeringAgentOutputValidationException('Architect repository_state must be an object when present.');
        }

        $decision = $output['architecture_decision'];
        $this->required($decision, ['affected_domains','primary_owner_domain','bounded_context','current_architecture','proposed_solution','components','interfaces','data_flow','dependencies','database_changes','api_changes','events','identity','permissions','tenant_isolation','security','migration_strategy','backward_compatibility','observability','testing_strategy','risks','alternatives_considered','decision']);
        $plan = $output['implementation_plan'];
        $this->required($plan, ['steps','files_to_create','files_to_modify','services','controllers','commands','entities','repositories','frontend_components','migrations','tests_required','documentation_updates','completion_conditions']);
        $handoff = $output['developer_handoff'];
        $this->required($handoff, ['mandatory_constraints','forbidden_changes','interfaces_to_respect','tests_required','completion_conditions']);

        $runtimeMetadataPresent = isset($output['repository_state']);

        if ($runtimeMetadataPresent) {
            if (!is_array($output['repository_state'] ?? null)) {
                throw new EngineeringAgentOutputValidationException('Runtime-enriched Architect output requires repository_state.');
            }
            $featureId = trim((string) ($decision['feature_id'] ?? ''));
            if ($featureId === '' || $featureId !== trim((string) ($plan['feature_id'] ?? '')) || $featureId !== trim((string) ($handoff['feature_id'] ?? ''))) {
                throw new EngineeringAgentOutputValidationException('Architect feature id must be present and consistent across artifacts.');
            }
            $revision = trim((string) ($decision['repository_revision'] ?? ''));
            if ($revision === '' || $revision === 'unknown' || trim((string) ($output['repository_state']['repository_revision'] ?? '')) !== $revision) {
                throw new EngineeringAgentOutputValidationException('Architect decision must be bound to the authoritative repository revision.');
            }
            if (($handoff['gate_status'] ?? null) !== $status) {
                throw new EngineeringAgentOutputValidationException('Developer handoff gate status must match Architect gate.');
            }
        }

        if (in_array($status, ['APPROVED','APPROVED_WITH_CONDITIONS'], true)) {
            $owner = trim((string) ($decision['primary_owner_domain'] ?? ''));
            if ($owner === '') throw new EngineeringAgentOutputValidationException('Approved architecture requires a primary owner domain.');
            if (!is_array($decision['affected_domains'] ?? null) || $decision['affected_domains'] === []) {
                throw new EngineeringAgentOutputValidationException('Approved architecture requires affected domains.');
            }
            if (!in_array($owner, array_map('strval', $decision['affected_domains']), true)) {
                throw new EngineeringAgentOutputValidationException('Primary owner domain must be included in affected domains.');
            }

            foreach (['bounded_context','current_architecture','proposed_solution'] as $field) {
                $value = $decision[$field] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    throw new EngineeringAgentOutputValidationException('Approved architecture requires explicit '.$field.'.');
                }
            }

            foreach (['identity','permissions','tenant_isolation','security','backward_compatibility','observability','testing_strategy'] as $field) {
                $value = $decision[$field] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    throw new EngineeringAgentOutputValidationException('Approved architecture requires explicit '.$field.'.');
                }
            }

            $architecturalDecision = $decision['decision'] ?? null;
            if ($architecturalDecision === null || $architecturalDecision === '' || $architecturalDecision === []) {
                throw new EngineeringAgentOutputValidationException('Approved architecture requires an explicit decision.');
            }
            if (!is_array($plan['steps'] ?? null) || $plan['steps'] === []) throw new EngineeringAgentOutputValidationException('Approved architecture requires implementation steps.');
            if (!is_array($plan['completion_conditions'] ?? null) || $plan['completion_conditions'] === []) throw new EngineeringAgentOutputValidationException('Approved architecture requires completion conditions.');
            if (!is_array($handoff['tests_required'] ?? null) || $handoff['tests_required'] === []) throw new EngineeringAgentOutputValidationException('Approved architecture requires Developer Handoff tests.');
            if (!is_array($handoff['completion_conditions'] ?? null) || $handoff['completion_conditions'] === []) throw new EngineeringAgentOutputValidationException('Approved architecture requires Developer Handoff completion conditions.');

            $plannedCreate = $this->architectPlanPaths($plan['files_to_create'] ?? []);
            $plannedModify = $this->architectPlanPaths($plan['files_to_modify'] ?? []);
            $documentationPaths = $this->architectPlanPaths($output['documentation_changes'] ?? []);
            $createModifyOverlap = array_intersect($plannedCreate, $plannedModify);
            if ($createModifyOverlap !== []) {
                throw new EngineeringAgentOutputValidationException('Architect cannot classify the same path as both create and modify.');
            }
            $overlap = array_intersect(array_merge($plannedCreate, $plannedModify), $documentationPaths);
            if ($overlap !== []) {
                throw new EngineeringAgentOutputValidationException('Architecture documentation paths must not overlap Developer implementation paths.');
            }
            if (count(array_unique(array_merge($plannedCreate, $plannedModify, $documentationPaths))) > 20) {
                throw new EngineeringAgentOutputValidationException('Approved architecture exceeds the 20-file repository mutation budget.');
            }
        }

        if ($status === 'APPROVED' && is_array($output['conditions'] ?? null) && $output['conditions'] !== []) {
            throw new EngineeringAgentOutputValidationException('APPROVED cannot carry mandatory conditions; use APPROVED_WITH_CONDITIONS.');
        }
        if ($status === 'APPROVED_WITH_CONDITIONS' && (!is_array($output['conditions']) || $output['conditions'] === [])) {
            throw new EngineeringAgentOutputValidationException('APPROVED_WITH_CONDITIONS requires explicit conditions.');
        }
        if ($status === 'NEEDS_HUMAN_DECISION') {
            $required = is_array($output['required_human_decisions']) ? $output['required_human_decisions'] : [];
            if (count($required) !== 1 || !is_array($required[0])) {
                throw new EngineeringAgentOutputValidationException('NEEDS_HUMAN_DECISION requires exactly one concrete human decision in V0.1.');
            }
            $this->required($required[0], ['question','reason','options']);
            if (trim((string) $required[0]['question']) === '' || trim((string) $required[0]['reason']) === '') {
                throw new EngineeringAgentOutputValidationException('Human decision question and reason cannot be empty.');
            }
            if (!is_array($required[0]['options']) || $required[0]['options'] === []) {
                throw new EngineeringAgentOutputValidationException('Human decision requires explicit options.');
            }

            $optionIds = [];
            foreach ($required[0]['options'] as $option) {
                $id = '';
                if (is_scalar($option)) {
                    $id = trim((string) $option);
                } elseif (is_array($option)) {
                    foreach (['id','value','option'] as $key) {
                        if (isset($option[$key]) && is_scalar($option[$key])) {
                            $id = trim((string) $option[$key]);
                            if ($id !== '') break;
                        }
                    }
                }
                if ($id === '') {
                    throw new EngineeringAgentOutputValidationException('Every human decision option requires a stable id/value.');
                }
                $normalized = strtoupper($id);
                if (isset($optionIds[$normalized])) {
                    throw new EngineeringAgentOutputValidationException('Human decision option ids must be unique.');
                }
                $optionIds[$normalized] = true;
            }

            $recommended = trim((string) ($required[0]['recommended_option'] ?? ''));
            if ($recommended !== '' && !isset($optionIds[strtoupper($recommended)])) {
                throw new EngineeringAgentOutputValidationException('Recommended human decision option must be one of the offered options.');
            }
        }

        $documentationRoots = [
            'docs/03-architecture/',
            'docs/04-domains/',
            'docs/06-ai-agents/',
            'docs/10-operations/',
            'docs/11-decisions/',
        ];
        foreach ($output['documentation_changes'] as $change) {
            if (!is_array($change)) throw new EngineeringAgentOutputValidationException('Architect documentation change must be an object.');
            $this->required($change, ['path','operation','content']);
            $path = str_replace('\\', '/', trim((string) $change['path']));
            $allowedRoot = false;
            foreach ($documentationRoots as $root) {
                if (str_starts_with($path, $root)) {
                    $allowedRoot = true;
                    break;
                }
            }
            if ($path === '' || !$allowedRoot || str_contains($path, '..') || str_contains($path, "\0") || !in_array((string) $change['operation'], ['CREATE','UPDATE'], true)) {
                throw new EngineeringAgentOutputValidationException('Architect may modify only approved architecture documentation roots.');
            }
        }
    }

    private function reviewer(array $output): void
    {
        $this->required($output, ['status','reviewed_revision','findings','acceptance_criteria','architecture_compliance','security_notes','recommendation']);
        if (($output['status'] ?? null) !== 'APPROVED') return;
        if (($output['architecture_compliance'] ?? null) !== true) {
            throw new EngineeringAgentOutputValidationException('Reviewer cannot APPROVE a non-compliant architecture implementation.');
        }
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

    /** @return list<string> */
    private function architectPlanPaths(mixed $files): array
    {
        if (!is_array($files)) return [];

        $paths = [];
        foreach ($files as $file) {
            $path = is_string($file)
                ? trim($file)
                : (is_array($file) && isset($file['path']) && is_string($file['path']) ? trim($file['path']) : '');
            if ($path === '') {
                throw new EngineeringAgentOutputValidationException('Architect implementation/documentation file entries require a path.');
            }
            if (isset($paths[$path])) {
                throw new EngineeringAgentOutputValidationException('Architect implementation/documentation file paths must be unique.');
            }
            $paths[$path] = true;
        }

        return array_keys($paths);
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
