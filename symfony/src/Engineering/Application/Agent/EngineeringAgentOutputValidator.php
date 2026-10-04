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
        $this->required($output, ['status','reviewed_revision','base_revision','pull_request','preflight','summary','issues','correctness','architecture','security','maintainability','database','api','tests','acceptance_criteria','ci','unresolved_blockers','unresolved_majors','recommendation']);
        $status = (string) ($output['status'] ?? '');
        if (!in_array($status, ['APPROVED','REQUEST_CHANGES','ARCHITECTURE_REVIEW_REQUIRED','HUMAN_REVIEW_REQUIRED'], true)) throw new EngineeringAgentOutputValidationException('Reviewer status is invalid.');
        if (!is_array($output['preflight'] ?? null)) throw new EngineeringAgentOutputValidationException('Reviewer preflight must be an object.');
        $this->required($output['preflight'], ['status','reviewed_revision','diff_complete','required_artifacts_present','ci_evidence_available','blockers']);
        if (!in_array((string) $output['preflight']['status'], ['PASS','BLOCKED'], true)) throw new EngineeringAgentOutputValidationException('Reviewer preflight status is invalid.');
        if ((string) $output['preflight']['reviewed_revision'] !== (string) $output['reviewed_revision']) throw new EngineeringAgentOutputValidationException('Reviewer preflight revision must match reviewed revision.');
        foreach (['correctness','security','maintainability','database','api','tests'] as $section) {
            if (!is_array($output[$section] ?? null)) throw new EngineeringAgentOutputValidationException('Reviewer '.$section.' must be an object.');
            $this->required($output[$section], ['status','findings']);
            if (!in_array((string) $output[$section]['status'], ['PASS','FINDINGS','NOT_APPLICABLE'], true)) throw new EngineeringAgentOutputValidationException('Reviewer '.$section.' status is invalid.');
        }
        if (!is_array($output['architecture'] ?? null)) throw new EngineeringAgentOutputValidationException('Reviewer architecture must be an object.');
        $this->required($output['architecture'], ['compliant','findings']);
        if (!is_array($output['ci'] ?? null)) throw new EngineeringAgentOutputValidationException('Reviewer CI evidence must be an object.');
        $this->required($output['ci'], ['state','total','passed','failed','pending','checks']);
        $blockingIssues = 0; $blockers = 0; $majors = 0;
        foreach ($output['issues'] as $issue) {
            if (!is_array($issue)) throw new EngineeringAgentOutputValidationException('Reviewer issue must be an object.');
            $this->required($issue, ['id','severity','blocking','file','line','category','problem','evidence','impact','expected_fix']);
            $severity = (string) $issue['severity'];
            if (!in_array($severity, ['BLOCKER','MAJOR','MINOR','SUGGESTION'], true)) throw new EngineeringAgentOutputValidationException('Reviewer issue severity is invalid.');
            if ($severity === 'BLOCKER') ++$blockers;
            if ($severity === 'MAJOR') ++$majors;
            if (($issue['blocking'] ?? false) === true) ++$blockingIssues;
            if (in_array($severity, ['BLOCKER','MAJOR'], true) && ($issue['blocking'] ?? false) !== true) throw new EngineeringAgentOutputValidationException('Reviewer BLOCKER/MAJOR issues must be blocking.');
            if ($severity === 'SUGGESTION' && ($issue['blocking'] ?? false) === true) throw new EngineeringAgentOutputValidationException('Reviewer SUGGESTION cannot be blocking.');
            if (trim((string) $issue['problem']) === '' || trim((string) $issue['impact']) === '' || trim((string) $issue['expected_fix']) === '') throw new EngineeringAgentOutputValidationException('Reviewer issue must be actionable.');
        }
        foreach ($output['acceptance_criteria'] as $criterion) {
            if (!is_array($criterion)) throw new EngineeringAgentOutputValidationException('Reviewer acceptance criterion must be an object.');
            $this->required($criterion, ['id','result','evidence']);
            if (!in_array((string) $criterion['result'], ['PASS','FAIL','NOT_COVERED'], true)) throw new EngineeringAgentOutputValidationException('Reviewer acceptance criterion result is invalid.');
        }
        if ($status === 'APPROVED') {
            if (($output['preflight']['status'] ?? null) !== 'PASS' || ($output['preflight']['diff_complete'] ?? false) !== true || ($output['preflight']['required_artifacts_present'] ?? false) !== true || ($output['preflight']['ci_evidence_available'] ?? false) !== true) throw new EngineeringAgentOutputValidationException('Reviewer APPROVED requires a complete PASS preflight.');
            if (($output['architecture']['compliant'] ?? null) !== true) throw new EngineeringAgentOutputValidationException('Reviewer cannot APPROVE architecture-noncompliant implementation.');
            if ($blockingIssues > 0 || $blockers > 0 || $majors > 0 || ($output['unresolved_blockers'] ?? []) !== [] || ($output['unresolved_majors'] ?? []) !== []) throw new EngineeringAgentOutputValidationException('Reviewer cannot APPROVE with unresolved blocking issues.');
            if ((int) ($output['ci']['failed'] ?? 0) > 0 || strtoupper((string) ($output['ci']['state'] ?? '')) === 'FAILED') throw new EngineeringAgentOutputValidationException('Reviewer cannot APPROVE with failed required CI.');
            foreach ($output['acceptance_criteria'] as $criterion) if (($criterion['result'] ?? null) !== 'PASS') throw new EngineeringAgentOutputValidationException('Reviewer APPROVED requires PASS evidence for every acceptance criterion.');
        }
        if ($status === 'REQUEST_CHANGES' && $blockingIssues === 0) throw new EngineeringAgentOutputValidationException('REQUEST_CHANGES requires at least one blocking actionable issue.');
        if ($status === 'ARCHITECTURE_REVIEW_REQUIRED') {
            if (($output['architecture']['compliant'] ?? true) !== false) throw new EngineeringAgentOutputValidationException('Architecture escalation requires explicit non-compliance.');
            $architectureEvidence = array_filter($output['issues'], static fn (mixed $issue): bool => is_array($issue) && (($issue['category'] ?? null) === 'ARCHITECTURE'));
            if ($architectureEvidence === []) throw new EngineeringAgentOutputValidationException('Architecture escalation requires concrete ARCHITECTURE issue evidence.');
        }
        if ($status === 'HUMAN_REVIEW_REQUIRED') {
            $human = $output['human_review'] ?? null;
            if (!is_array($human)) throw new EngineeringAgentOutputValidationException('HUMAN_REVIEW_REQUIRED requires human_review evidence.');
            $this->required($human, ['reason','decision_required']);
            if (trim((string) $human['reason']) === '' || trim((string) $human['decision_required']) === '') throw new EngineeringAgentOutputValidationException('Human review reason and decision cannot be empty.');
        }
    }

    private function developer(array $output): void
    {
        $this->required($output, [
            'status','preflight','scope','changed_files','implementation_summary','database_changes','api_changes',
            'acceptance_criteria_evidence','tests_added','tests_run','validation','architecture_compliance','security',
            'known_limitations','deviations_from_plan','risks','findings','follow_up_required','changes',
        ]);

        $status = (string) ($output['status'] ?? '');
        $allowed = [
            'COMPLETED','COMPLETED_WITH_LIMITATIONS','BLOCKED',
            'ARCHITECTURE_REVIEW_REQUIRED','SPECIFICATION_REVIEW_REQUIRED','SECURITY_REVIEW_REQUIRED','FAILED',
        ];
        if (!in_array($status, $allowed, true)) {
            throw new EngineeringAgentOutputValidationException('Developer status is invalid.');
        }

        foreach (['preflight','scope','validation','architecture_compliance','security'] as $section) {
            if (!is_array($output[$section] ?? null)) {
                throw new EngineeringAgentOutputValidationException('Developer '.$section.' must be an object.');
            }
        }
        $this->required($output['preflight'], ['status','blockers','architecture_conflicts']);
        $this->required($output['scope'], ['requested','implemented','not_implemented']);
        $this->required($output['validation'], ['commands_required','passed','failed','skipped']);
        $this->required($output['architecture_compliance'], ['adr_followed','deviations']);
        $this->required($output['security'], ['checks_performed','findings']);

        if (!in_array((string) $output['preflight']['status'], ['PASS','BLOCKED'], true)) {
            throw new EngineeringAgentOutputValidationException('Developer preflight status is invalid.');
        }

        $reviewStatuses = ['ARCHITECTURE_REVIEW_REQUIRED','SPECIFICATION_REVIEW_REQUIRED','SECURITY_REVIEW_REQUIRED'];
        if (in_array($status, $reviewStatuses, true)) {
            if (($output['changes'] ?? []) !== []) {
                throw new EngineeringAgentOutputValidationException('Developer review escalation must not contain repository mutations.');
            }
            if (
                ($output['preflight']['blockers'] ?? []) === []
                && ($output['preflight']['architecture_conflicts'] ?? []) === []
                && ($output['deviations_from_plan'] ?? []) === []
                && ($output['findings'] ?? []) === []
            ) {
                throw new EngineeringAgentOutputValidationException('Developer review escalation requires concrete evidence.');
            }
            return;
        }

        if (in_array($status, ['BLOCKED','FAILED'], true)) return;

        if (($output['preflight']['status'] ?? null) !== 'PASS') {
            throw new EngineeringAgentOutputValidationException('Developer completion requires PASS preflight.');
        }
        if (($output['architecture_compliance']['adr_followed'] ?? null) !== true) {
            throw new EngineeringAgentOutputValidationException('Developer completion requires explicit ADR compliance.');
        }
        if (!is_array($output['validation']['failed'] ?? null) || $output['validation']['failed'] !== []) {
            throw new EngineeringAgentOutputValidationException('Developer cannot complete with failed required validation checks.');
        }
        if ($status === 'COMPLETED' && ($output['known_limitations'] ?? []) !== []) {
            throw new EngineeringAgentOutputValidationException('Developer COMPLETED cannot contain known limitations; use COMPLETED_WITH_LIMITATIONS.');
        }
        if ($status === 'COMPLETED_WITH_LIMITATIONS' && ($output['known_limitations'] ?? []) === []) {
            throw new EngineeringAgentOutputValidationException('Developer COMPLETED_WITH_LIMITATIONS requires explicit limitations.');
        }
        if (!is_array($output['changes'] ?? null) || $output['changes'] === []) {
            throw new EngineeringAgentOutputValidationException('Developer completion requires at least one repository change.');
        }

        foreach ($output['changes'] as $change) {
            if (!is_array($change)) throw new EngineeringAgentOutputValidationException('Developer change must be an object.');
            $this->required($change, ['path','operation']);
            $path = trim((string) $change['path']);
            $operation = (string) $change['operation'];
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
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
        $this->required($output, ['phase','status','feature_id','tested_revision','pull_request','test_plan','test_changes']);

        $phase = (string) $output['phase'];
        $status = (string) $output['status'];
        if (!in_array($phase, ['PLAN','EXECUTION'], true)) throw new EngineeringAgentOutputValidationException('QA phase is invalid.');
        if (!is_array($output['test_plan'] ?? null)) throw new EngineeringAgentOutputValidationException('QA test_plan must be an object.');
        $this->required($output['test_plan'], ['feature_id','version','scenarios','permissions','tenant_cases','api_cases','database_cases','ui_cases','regression_cases','performance_cases','required_suites','prerequisites','test_data','environment_requirements','blocking_checks']);

        if ($phase === 'PLAN') {
            if ($status !== 'PLAN_READY') throw new EngineeringAgentOutputValidationException('QA planning phase must return PLAN_READY.');
            if (($output['tested_revision'] ?? null) !== null || ($output['test_changes'] ?? []) !== []) {
                throw new EngineeringAgentOutputValidationException('QA planning cannot claim a tested revision or mutate tests.');
            }
            if ((string) ($output['test_plan']['feature_id'] ?? '') !== (string) $output['feature_id']) {
                throw new EngineeringAgentOutputValidationException('QA Test Plan feature id must match output feature id.');
            }
            return;
        }

        if (!in_array($status, ['PASS','FAIL','BLOCKED','HUMAN_TEST_REQUIRED','TESTS_UPDATED'], true)) {
            throw new EngineeringAgentOutputValidationException('QA execution status is invalid.');
        }
        $this->required($output, ['tests','acceptance_criteria','system_invariants','regressions','defects','security_findings','known_limitations','human_tests_required','blockers','repository_revision_after_tests']);
        if ($status !== 'TESTS_UPDATED' && ($output['test_changes'] ?? []) !== []) {
            throw new EngineeringAgentOutputValidationException('QA test mutations require TESTS_UPDATED status.');
        }
        if (trim((string) ($output['tested_revision'] ?? '')) === '') throw new EngineeringAgentOutputValidationException('QA execution requires tested_revision.');
        if (!is_array($output['tests'] ?? null)) throw new EngineeringAgentOutputValidationException('QA tests must be an object.');
        $this->required($output['tests'], ['total','passed','failed','skipped','suites']);
        $total = (int) $output['tests']['total'];
        $passed = (int) $output['tests']['passed'];
        $failed = (int) $output['tests']['failed'];
        $skipped = (int) $output['tests']['skipped'];
        if ($passed + $failed + $skipped > $total) throw new EngineeringAgentOutputValidationException('QA test totals are inconsistent.');

        foreach ($output['test_changes'] as $change) {
            if (!is_array($change)) throw new EngineeringAgentOutputValidationException('QA test change must be an object.');
            $this->required($change, ['path','operation','content']);
            $path = trim((string) $change['path']);
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) throw new EngineeringAgentOutputValidationException('QA test change path is unsafe.');
            if (!(str_starts_with($path, 'tests/') || str_starts_with($path, 'symfony/tests/'))) throw new EngineeringAgentOutputValidationException('QA may modify only test roots.');
        }

        foreach ($output['acceptance_criteria'] as $criterion) {
            if (!is_array($criterion)) throw new EngineeringAgentOutputValidationException('QA acceptance criterion must be an object.');
            $this->required($criterion, ['id','status','evidence']);
            if (!in_array((string) $criterion['status'], ['PASS','FAIL'], true)) throw new EngineeringAgentOutputValidationException('QA acceptance criterion status is invalid.');
            if (($criterion['status'] ?? null) === 'PASS' && ($criterion['evidence'] ?? null) === []) throw new EngineeringAgentOutputValidationException('QA PASS criterion requires evidence.');
        }

        foreach ($output['system_invariants'] as $name => $invariant) {
            if (!is_array($invariant)) throw new EngineeringAgentOutputValidationException('QA system invariant '.$name.' must be an object.');
            $this->required($invariant, ['applicable','status','evidence','reason']);
            if (($invariant['applicable'] ?? false) === true) {
                if (!in_array((string) $invariant['status'], ['PASS','FAIL'], true)) throw new EngineeringAgentOutputValidationException('Applicable QA invariant must PASS or FAIL.');
                if (($invariant['status'] ?? null) === 'PASS' && ($invariant['evidence'] ?? null) === null) throw new EngineeringAgentOutputValidationException('Applicable QA invariant PASS requires evidence.');
            } else {
                if (($invariant['status'] ?? null) !== 'NOT_APPLICABLE' || trim((string) ($invariant['reason'] ?? '')) === '') throw new EngineeringAgentOutputValidationException('Non-applicable QA invariant requires NOT_APPLICABLE and reason.');
            }
        }

        if ($status === 'PASS') {
            if ($failed > 0) throw new EngineeringAgentOutputValidationException('QA cannot PASS with failed tests.');
            if (($output['test_changes'] ?? []) !== []) throw new EngineeringAgentOutputValidationException('QA PASS cannot contain unreviewed test mutations.');
            foreach ($output['acceptance_criteria'] as $criterion) if (($criterion['status'] ?? null) !== 'PASS') throw new EngineeringAgentOutputValidationException('QA PASS requires every acceptance criterion to PASS.');
            foreach ($output['system_invariants'] as $invariant) if (($invariant['applicable'] ?? false) === true && ($invariant['status'] ?? null) !== 'PASS') throw new EngineeringAgentOutputValidationException('QA PASS requires every applicable COS invariant to PASS.');
            foreach (array_merge($output['defects'], $output['security_findings']) as $finding) {
                if (is_array($finding) && in_array(strtoupper((string) ($finding['severity'] ?? '')), ['BLOCKER','MAJOR','CRITICAL','HIGH'], true)) throw new EngineeringAgentOutputValidationException('QA cannot PASS with blocking defect/security finding.');
            }
        }

        if ($status === 'FAIL') {
            $hasFailedCriterion = false;
            foreach ($output['acceptance_criteria'] as $criterion) if (($criterion['status'] ?? null) === 'FAIL') $hasFailedCriterion = true;
            if ($failed === 0 && !$hasFailedCriterion && ($output['defects'] ?? []) === [] && ($output['security_findings'] ?? []) === []) throw new EngineeringAgentOutputValidationException('QA FAIL requires concrete failure evidence.');
        }
        if ($status === 'BLOCKED' && ($output['blockers'] ?? []) === []) throw new EngineeringAgentOutputValidationException('QA BLOCKED requires concrete blockers.');
        if ($status === 'HUMAN_TEST_REQUIRED' && ($output['human_tests_required'] ?? []) === []) throw new EngineeringAgentOutputValidationException('HUMAN_TEST_REQUIRED requires explicit manual scenarios.');
        if ($status === 'TESTS_UPDATED' && ($output['test_changes'] ?? []) === []) throw new EngineeringAgentOutputValidationException('TESTS_UPDATED requires bounded test changes.');
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
