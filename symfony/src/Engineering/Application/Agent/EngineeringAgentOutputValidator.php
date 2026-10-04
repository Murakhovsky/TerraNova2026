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

        $status = (string) ($output['status'] ?? '');
        if (!in_array($status, ['SPECIFICATION_READY','HUMAN_DECISION_REQUIRED','BLOCKED','FAILED'], true)) {
            throw new EngineeringAgentOutputValidationException('Manager status is invalid.');
        }

        if (!is_array($output['feature'])) {
            throw new EngineeringAgentOutputValidationException('Manager feature must be an object.');
        }
        if (!is_array($output['context_map'])) {
            throw new EngineeringAgentOutputValidationException('Manager context_map must be an object.');
        }
        if (!is_array($output['decision'])) {
            throw new EngineeringAgentOutputValidationException('Manager decision must be an object.');
        }

        $feature = $output['feature'];
        $this->required($feature, [
            'title','type','business_goal','user_problem','current_behavior','expected_behavior',
            'scope','out_of_scope','affected_areas','user_roles','functional_requirements',
            'non_functional_requirements','acceptance_criteria','dependencies','constraints',
            'risks','assumptions','open_questions','priority','complexity',
        ]);

        foreach (['title','business_goal','expected_behavior'] as $field) {
            if (trim((string) ($feature[$field] ?? '')) === '') {
                throw new EngineeringAgentOutputValidationException('Manager '.$field.' cannot be empty.');
            }
        }
        if (!in_array((string) $feature['type'], ['FEATURE','BUG','REFACTOR','MIGRATION','MAINTENANCE'], true)) {
            throw new EngineeringAgentOutputValidationException('Manager feature type is invalid.');
        }
        if (!in_array((string) $feature['priority'], ['P0','P1','P2','P3'], true)) {
            throw new EngineeringAgentOutputValidationException('Manager priority is invalid.');
        }
        if (!in_array((string) $feature['complexity'], ['XS','S','M','L','XL'], true)) {
            throw new EngineeringAgentOutputValidationException('Manager complexity is invalid.');
        }

        foreach (['scope','out_of_scope','affected_areas','user_roles','functional_requirements','non_functional_requirements','acceptance_criteria','dependencies','constraints','risks','assumptions','open_questions'] as $field) {
            if (!is_array($feature[$field])) {
                throw new EngineeringAgentOutputValidationException('Manager feature '.$field.' must be an array.');
            }
        }
        if ($feature['scope'] === []) {
            throw new EngineeringAgentOutputValidationException('Manager scope cannot be empty.');
        }
        if ($feature['functional_requirements'] === []) {
            throw new EngineeringAgentOutputValidationException('Manager functional requirements cannot be empty.');
        }
        if ($feature['acceptance_criteria'] === []) {
            throw new EngineeringAgentOutputValidationException('Manager acceptance criteria cannot be empty.');
        }

        $functionalIds = [];
        foreach ($feature['functional_requirements'] as $index => $requirement) {
            if (!is_array($requirement)) {
                throw new EngineeringAgentOutputValidationException('Manager functional requirement must be an object.');
            }
            $this->required($requirement, ['id','description']);
            $id = strtoupper(trim((string) $requirement['id']));
            if (!preg_match('/^FR-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Functional requirement %d has invalid id.', $index));
            }
            if (isset($functionalIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Functional requirement ids must be unique.');
            }
            if (trim((string) $requirement['description']) === '') {
                throw new EngineeringAgentOutputValidationException('Functional requirement description cannot be empty.');
            }
            $functionalIds[$id] = true;
        }

        $nonFunctionalIds = [];
        foreach ($feature['non_functional_requirements'] as $index => $requirement) {
            if (!is_array($requirement)) {
                throw new EngineeringAgentOutputValidationException('Manager non-functional requirement must be an object.');
            }
            $this->required($requirement, ['id','description']);
            $id = strtoupper(trim((string) $requirement['id']));
            if (!preg_match('/^NFR-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Non-functional requirement %d has invalid id.', $index));
            }
            if (isset($nonFunctionalIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Non-functional requirement ids must be unique.');
            }
            if (trim((string) $requirement['description']) === '') {
                throw new EngineeringAgentOutputValidationException('Non-functional requirement description cannot be empty.');
            }
            $nonFunctionalIds[$id] = true;
        }

        $acceptanceIds = [];
        $verificationTypes = ['unit','integration','api','ui','e2e','manual','security'];
        foreach ($feature['acceptance_criteria'] as $index => $criterion) {
            if (!is_array($criterion)) {
                throw new EngineeringAgentOutputValidationException('Acceptance criterion must be an object.');
            }
            $this->required($criterion, ['id','description','verification_type']);
            $id = strtoupper(trim((string) $criterion['id']));
            if (!preg_match('/^AC-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Acceptance criterion %d has invalid id.', $index));
            }
            if (isset($acceptanceIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Acceptance criterion ids must be unique.');
            }
            if (trim((string) $criterion['description']) === '') {
                throw new EngineeringAgentOutputValidationException('Acceptance criterion description cannot be empty.');
            }
            if (!in_array((string) $criterion['verification_type'], $verificationTypes, true)) {
                throw new EngineeringAgentOutputValidationException('Acceptance criterion verification_type is invalid.');
            }
            $acceptanceIds[$id] = true;
        }

        $riskIds = [];
        $riskCategories = [
            'SECURITY','TENANT','AUTH','DATABASE','MIGRATION','BREAKING_CHANGE','API',
            'PERFORMANCE','DATA_LOSS','UX','DEPENDENCY','DEPLOYMENT','UNKNOWN_SCOPE',
        ];
        foreach ($feature['risks'] as $index => $risk) {
            if (!is_array($risk)) {
                throw new EngineeringAgentOutputValidationException('Manager risk must be an object.');
            }
            $this->required($risk, ['id','category','description','severity','reason','mitigation']);
            $id = strtoupper(trim((string) $risk['id']));
            if (!preg_match('/^RISK-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Risk %d has invalid id.', $index));
            }
            if (isset($riskIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Risk ids must be unique.');
            }
            if (!in_array((string) $risk['category'], $riskCategories, true)) {
                throw new EngineeringAgentOutputValidationException('Manager risk category is invalid.');
            }
            if (!in_array((string) $risk['severity'], ['low','medium','high','critical'], true)) {
                throw new EngineeringAgentOutputValidationException('Manager risk severity is invalid.');
            }
            foreach (['description','reason','mitigation'] as $field) {
                if (trim((string) $risk[$field]) === '') {
                    throw new EngineeringAgentOutputValidationException('Manager risk '.$field.' cannot be empty.');
                }
            }
            $riskIds[$id] = true;
        }

        $assumptionIds = [];
        foreach ($feature['assumptions'] as $index => $assumption) {
            if (!is_array($assumption)) {
                throw new EngineeringAgentOutputValidationException('Manager assumption must be an object.');
            }
            $this->required($assumption, ['id','description']);
            $id = strtoupper(trim((string) $assumption['id']));
            if (!preg_match('/^ASM-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Assumption %d has invalid id.', $index));
            }
            if (isset($assumptionIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Assumption ids must be unique.');
            }
            if (trim((string) $assumption['description']) === '') {
                throw new EngineeringAgentOutputValidationException('Manager assumption description cannot be empty.');
            }
            $assumptionIds[$id] = true;
        }

        $questionIds = [];
        $blockingHumanQuestions = [];
        $questionClassifications = [
            'RESOLVABLE_FROM_CODE','RESOLVABLE_FROM_DOCS','ARCHITECT_DECISION',
            'PRODUCT_DECISION','BLOCKING_USER_DECISION','NON_BLOCKING',
        ];
        foreach ($feature['open_questions'] as $index => $question) {
            if (!is_array($question)) {
                throw new EngineeringAgentOutputValidationException('Manager open question must be an object.');
            }
            $this->required($question, ['id','question','classification','blocking','reason','options']);
            $id = strtoupper(trim((string) $question['id']));
            if (!preg_match('/^Q-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Open question %d has invalid id.', $index));
            }
            if (isset($questionIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Open question ids must be unique.');
            }
            if (trim((string) $question['question']) === '' || trim((string) $question['reason']) === '') {
                throw new EngineeringAgentOutputValidationException('Open question and reason cannot be empty.');
            }
            if (!in_array((string) $question['classification'], $questionClassifications, true)) {
                throw new EngineeringAgentOutputValidationException('Open question classification is invalid.');
            }
            if (!is_bool($question['blocking']) || !is_array($question['options'])) {
                throw new EngineeringAgentOutputValidationException('Open question blocking/options types are invalid.');
            }
            if ($question['classification'] === 'BLOCKING_USER_DECISION' && $question['blocking'] !== true) {
                throw new EngineeringAgentOutputValidationException('BLOCKING_USER_DECISION must be blocking.');
            }
            if ($question['blocking'] === true) {
                if (!in_array((string) $question['classification'], ['PRODUCT_DECISION','BLOCKING_USER_DECISION'], true)) {
                    throw new EngineeringAgentOutputValidationException('Only product/user decisions may block Manager analysis.');
                }
                $blockingHumanQuestions[] = $question;
            }
            $questionIds[$id] = true;
        }

        if (!is_array($output['risks']) || !is_array($output['assumptions']) || !is_array($output['open_questions'])) {
            throw new EngineeringAgentOutputValidationException('Manager canonical workflow copies must be arrays.');
        }
        if ($output['risks'] !== $feature['risks'] || $output['assumptions'] !== $feature['assumptions'] || $output['open_questions'] !== $feature['open_questions']) {
            throw new EngineeringAgentOutputValidationException('Manager top-level risks/assumptions/open_questions must match Feature Specification.');
        }

        if (!is_array($output['tasks']) || $output['tasks'] === []) {
            throw new EngineeringAgentOutputValidationException('Manager must create at least one engineering task.');
        }

        $taskIds = [];
        $taskDependencies = [];
        foreach ($output['tasks'] as $index => $task) {
            if (!is_array($task)) {
                throw new EngineeringAgentOutputValidationException('Engineering task must be an object.');
            }
            $this->required($task, ['id','title','type','description','dependencies','acceptance_criteria','assigned_role','status']);
            $id = strtoupper(trim((string) $task['id']));
            if (!preg_match('/^[A-Z][A-Z0-9_-]*-[0-9]{3,}$/', $id)) {
                throw new EngineeringAgentOutputValidationException(sprintf('Engineering task %d requires a stable id.', $index));
            }
            if (isset($taskIds[$id])) {
                throw new EngineeringAgentOutputValidationException('Engineering task ids must be unique.');
            }
            if (trim((string) $task['title']) === '' || trim((string) $task['description']) === '') {
                throw new EngineeringAgentOutputValidationException('Engineering task title and description cannot be empty.');
            }
            if (!in_array((string) $task['type'], ['ARCHITECTURE','BACKEND','FRONTEND','DATABASE','TEST','DOCUMENTATION','REVIEW','SECURITY','DEVOPS','RESEARCH'], true)) {
                throw new EngineeringAgentOutputValidationException('Engineering task type is invalid.');
            }
            if (!in_array((string) $task['assigned_role'], ['PRINCIPAL_ARCHITECT','DEVELOPER','REVIEWER','QA'], true)) {
                throw new EngineeringAgentOutputValidationException('Engineering task assigned_role is invalid.');
            }
            if (($task['status'] ?? null) !== 'PENDING') {
                throw new EngineeringAgentOutputValidationException('Manager-created engineering task status must be PENDING.');
            }
            if (!is_array($task['dependencies']) || !is_array($task['acceptance_criteria'])) {
                throw new EngineeringAgentOutputValidationException('Engineering task dependencies and acceptance_criteria must be arrays.');
            }
            foreach ($task['acceptance_criteria'] as $criterionId) {
                $criterionId = strtoupper(trim((string) $criterionId));
                if ($criterionId === '' || !isset($acceptanceIds[$criterionId])) {
                    throw new EngineeringAgentOutputValidationException('Engineering task references an unknown Acceptance Criterion.');
                }
            }
            $taskIds[$id] = true;
            $taskDependencies[$id] = array_map(static fn (mixed $dependency): string => strtoupper(trim((string) $dependency)), $task['dependencies']);
        }

        foreach ($taskDependencies as $taskId => $dependencies) {
            foreach ($dependencies as $dependency) {
                if ($dependency === '' || !isset($taskIds[$dependency])) {
                    throw new EngineeringAgentOutputValidationException('Engineering task references an unknown dependency.');
                }
                if ($dependency === $taskId) {
                    throw new EngineeringAgentOutputValidationException('Engineering task cannot depend on itself.');
                }
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (string $taskId) use (&$visit, &$visiting, &$visited, $taskDependencies): void {
            if (isset($visited[$taskId])) return;
            if (isset($visiting[$taskId])) {
                throw new EngineeringAgentOutputValidationException('Engineering task dependency graph contains a cycle.');
            }
            $visiting[$taskId] = true;
            foreach ($taskDependencies[$taskId] ?? [] as $dependency) $visit($dependency);
            unset($visiting[$taskId]);
            $visited[$taskId] = true;
        };
        foreach (array_keys($taskIds) as $taskId) $visit($taskId);

        if (($feature['complexity'] ?? null) === 'XL' && count($output['tasks']) < 2) {
            throw new EngineeringAgentOutputValidationException('XL feature must be decomposed into multiple tasks.');
        }

        $this->required($output['decision'], ['type','agent','reason','human_decision']);
        $decisionType = (string) $output['decision']['type'];
        $decisionAgent = $output['decision']['agent'];
        $humanDecision = $output['decision']['human_decision'];
        if (trim((string) $output['decision']['reason']) === '') {
            throw new EngineeringAgentOutputValidationException('Manager decision reason cannot be empty.');
        }

        if ($status === 'SPECIFICATION_READY') {
            if ($decisionType !== 'RUN_AGENT' || strtoupper((string) $decisionAgent) !== 'PRINCIPAL_ARCHITECT' || $humanDecision !== null) {
                throw new EngineeringAgentOutputValidationException('SPECIFICATION_READY must route to Principal Architect without a human decision.');
            }
            if ($blockingHumanQuestions !== []) {
                throw new EngineeringAgentOutputValidationException('SPECIFICATION_READY cannot contain blocking product/user questions.');
            }
            return;
        }

        if ($status === 'HUMAN_DECISION_REQUIRED') {
            if ($decisionType !== 'REQUEST_HUMAN_DECISION' || $decisionAgent !== null || !is_array($humanDecision)) {
                throw new EngineeringAgentOutputValidationException('HUMAN_DECISION_REQUIRED requires a concrete human decision and no agent routing.');
            }
            if (count($blockingHumanQuestions) !== 1) {
                throw new EngineeringAgentOutputValidationException('HUMAN_DECISION_REQUIRED requires exactly one blocking product/user question.');
            }
            $this->required($humanDecision, ['question','reason','options','recommended_option','evidence']);
            if (trim((string) $humanDecision['question']) === '' || trim((string) $humanDecision['reason']) === '') {
                throw new EngineeringAgentOutputValidationException('Manager human decision question and reason cannot be empty.');
            }
            if (!is_array($humanDecision['options']) || $humanDecision['options'] === []) {
                throw new EngineeringAgentOutputValidationException('Manager human decision requires options.');
            }
            if (!is_array($humanDecision['evidence'])) {
                throw new EngineeringAgentOutputValidationException('Manager human decision evidence must be an array.');
            }

            $optionIds = [];
            foreach ($humanDecision['options'] as $option) {
                if (!is_array($option)) {
                    throw new EngineeringAgentOutputValidationException('Manager human decision option must be an object.');
                }
                $this->required($option, ['id','label']);
                $optionId = trim((string) $option['id']);
                if ($optionId === '' || trim((string) $option['label']) === '') {
                    throw new EngineeringAgentOutputValidationException('Manager human decision options require id and label.');
                }
                $normalized = strtoupper($optionId);
                if (isset($optionIds[$normalized])) {
                    throw new EngineeringAgentOutputValidationException('Manager human decision option ids must be unique.');
                }
                $optionIds[$normalized] = true;
            }
            $recommended = trim((string) ($humanDecision['recommended_option'] ?? ''));
            if ($recommended !== '' && !isset($optionIds[strtoupper($recommended)])) {
                throw new EngineeringAgentOutputValidationException('Manager recommended human option must be one of the offered options.');
            }
            return;
        }

        if ($status === 'BLOCKED') {
            if ($decisionType !== 'BLOCK' || $decisionAgent !== null || $humanDecision !== null) {
                throw new EngineeringAgentOutputValidationException('BLOCKED Manager result must use BLOCK with no agent/human decision.');
            }
            return;
        }

        if ($decisionType !== 'STOP' || $decisionAgent !== null || $humanDecision !== null) {
            throw new EngineeringAgentOutputValidationException('FAILED Manager result must use STOP with no agent/human decision.');
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
