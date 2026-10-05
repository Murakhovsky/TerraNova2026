<?php
declare(strict_types=1);

namespace App\Engineering\Application\Acceptance;

use InvalidArgumentException;

final class EngineeringV01AcceptanceVerifier
{
    /** @var list<string> */
    private const SCENARIOS = ['success', 'fix-loop', 'human-gate', 'recovery'];

    /** @var list<string> */
    private const REQUIRED_ARTIFACTS = [
        'FEATURE_SPEC',
        'CONTEXT_MAP',
        'TEST_PLAN',
        'ARCHITECTURE_DECISION',
        'IMPLEMENTATION_PLAN',
        'DEVELOPER_HANDOFF',
        'DEVELOPMENT_RESULT',
        'REVIEW_REPORT',
        'QA_REPORT',
        'FINAL_REPORT',
    ];

    /** @var list<string> */
    private const REQUIRED_ROLES = [
        'ENGINEERING_MANAGER',
        'QA',
        'PRINCIPAL_ARCHITECT',
        'DEVELOPER',
        'REVIEWER',
    ];

    /** @var list<string> */
    private const RESUMABLE_STATES = [
        'ANALYSIS',
        'QA_PLANNING',
        'ARCHITECTURE_PENDING',
        'DEVELOPMENT_RUNNING',
        'REVIEW_PENDING',
        'QA_PENDING',
    ];

    /**
     * @param array<string,mixed> $status
     * @param list<array<string,mixed>> $audit
     * @param list<array<string,mixed>> $humanDecisions
     * @return array{feature_id:string,scenario:string,passed:bool,checks:list<array{id:string,passed:bool,detail:string}>,failures:list<string>}
     */
    public function verify(string $featureId, string $scenario, array $status, array $audit, array $humanDecisions): array
    {
        $scenario = strtolower(trim($scenario));
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new InvalidArgumentException('Unknown Engineering V0.1 acceptance scenario: '.$scenario.'.');
        }

        $feature = is_array($status['feature'] ?? null) ? $status['feature'] : [];
        $workflow = is_array($status['workflow'] ?? null) ? $status['workflow'] : [];
        $artifacts = is_array($status['artifacts'] ?? null) ? $status['artifacts'] : [];
        $runs = is_array($status['agent_runs'] ?? null) ? $status['agent_runs'] : [];
        $findings = is_array($status['findings'] ?? null) ? $status['findings'] : [];
        $openDecisions = is_array($status['open_human_decisions'] ?? null) ? $status['open_human_decisions'] : [];
        $checks = [];

        $state = (string) ($workflow['state'] ?? '');
        $featureStatus = (string) ($feature['status'] ?? '');
        $checks[] = $this->check(
            'ready_state',
            in_array($state, ['READY_FOR_HUMAN_APPROVAL', 'DONE'], true),
            'Workflow state is '.$state.'.',
        );
        $checks[] = $this->check(
            'feature_workflow_state_consistent',
            $state !== '' && $featureStatus === $state,
            'Feature status='.$featureStatus.'; workflow state='.$state.'.',
        );

        $missingArtifacts = array_values(array_filter(
            self::REQUIRED_ARTIFACTS,
            static fn (string $type): bool => !isset($artifacts[$type]) || !is_array($artifacts[$type]),
        ));
        $checks[] = $this->check(
            'canonical_artifacts_persisted',
            $missingArtifacts === [],
            $missingArtifacts === [] ? 'All canonical release artifacts are present.' : 'Missing: '.implode(', ', $missingArtifacts).'.',
        );

        $roles = [];
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $role = trim((string) ($run['role'] ?? ''));
            if ($role !== '') $roles[$role] = true;
        }
        $missingRoles = array_values(array_filter(
            self::REQUIRED_ROLES,
            static fn (string $role): bool => !isset($roles[$role]),
        ));
        $checks[] = $this->check(
            'all_roles_executed',
            $missingRoles === [],
            $missingRoles === [] ? 'All five Engineering roles executed.' : 'Missing roles: '.implode(', ', $missingRoles).'.',
        );

        $qaPlan = false;
        $qaPass = false;
        foreach ($runs as $run) {
            if (!is_array($run) || ($run['role'] ?? null) !== 'QA') continue;
            $output = is_array($run['output'] ?? null) ? $run['output'] : [];
            if (($output['phase'] ?? null) === 'PLAN' && ($output['status'] ?? null) === 'PLAN_READY') $qaPlan = true;
            if (($output['phase'] ?? null) === 'EXECUTION' && ($output['status'] ?? null) === 'PASS') $qaPass = true;
        }
        $checks[] = $this->check('qa_planning_before_release', $qaPlan, $qaPlan ? 'QA PLAN_READY evidence exists.' : 'No QA PLAN_READY run found.');
        $checks[] = $this->check('qa_execution_passed', $qaPass, $qaPass ? 'QA PASS execution evidence exists.' : 'No QA execution PASS run found.');

        $blockingFindings = array_values(array_filter($findings, static function (mixed $finding): bool {
            if (!is_array($finding) || ($finding['status'] ?? null) !== 'OPEN') return false;
            return in_array(strtoupper((string) ($finding['severity'] ?? '')), ['HIGH', 'CRITICAL', 'MAJOR', 'BLOCKER'], true);
        }));
        $checks[] = $this->check(
            'no_open_blocking_findings',
            $blockingFindings === [],
            $blockingFindings === [] ? 'No open HIGH/CRITICAL findings.' : count($blockingFindings).' blocking finding(s) remain open.',
        );
        $checks[] = $this->check(
            'no_open_human_decision',
            $openDecisions === [],
            $openDecisions === [] ? 'No blocking human decision remains open.' : count($openDecisions).' human decision(s) remain open.',
        );

        $running = array_values(array_filter($runs, static fn (mixed $run): bool => is_array($run) && ($run['status'] ?? null) === 'RUNNING'));
        $checks[] = $this->check(
            'no_running_agent_run',
            $running === [],
            $running === [] ? 'No AgentRun is left RUNNING.' : count($running).' AgentRun(s) are still RUNNING.',
        );

        $idempotency = [];
        $duplicateKeys = [];
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $key = trim((string) ($run['idempotency_key'] ?? ''));
            if ($key === '') continue;
            if (isset($idempotency[$key])) $duplicateKeys[$key] = true;
            $idempotency[$key] = true;
        }
        $checks[] = $this->check(
            'agent_run_idempotency_unique',
            $duplicateKeys === [],
            $duplicateKeys === [] ? 'AgentRun idempotency keys are unique.' : 'Duplicate keys: '.implode(', ', array_keys($duplicateKeys)).'.',
        );

        $checks[] = $this->revisionChainCheck($artifacts, $runs);
        $checks[] = $this->pullRequestCheck($artifacts, $runs);

        $final = is_array($artifacts['FINAL_REPORT']['content'] ?? null) ? $artifacts['FINAL_REPORT']['content'] : [];
        $recommendation = (string) ($final['recommendation'] ?? '');
        $checks[] = $this->check(
            'final_report_ready',
            in_array($recommendation, ['READY_FOR_HUMAN_APPROVAL', 'DONE'], true),
            'Final Report recommendation='.$recommendation.'.',
        );

        if ($scenario === 'fix-loop') {
            $this->appendFixLoopChecks($checks, $runs, $audit);
        } elseif ($scenario === 'human-gate') {
            $this->appendHumanGateChecks($checks, $audit, $humanDecisions);
        } elseif ($scenario === 'recovery') {
            $this->appendRecoveryChecks($checks, $feature, $workflow, $audit);
        }

        $failures = array_values(array_map(
            static fn (array $check): string => $check['id'],
            array_filter($checks, static fn (array $check): bool => !$check['passed']),
        ));

        return [
            'feature_id' => $featureId,
            'scenario' => $scenario,
            'passed' => $failures === [],
            'checks' => $checks,
            'failures' => $failures,
        ];
    }

    /** @param array<string,mixed> $artifacts @param list<array<string,mixed>> $runs */
    private function revisionChainCheck(array $artifacts, array $runs): array
    {
        $architecture = is_array($artifacts['ARCHITECTURE_DECISION']['content'] ?? null) ? $artifacts['ARCHITECTURE_DECISION']['content'] : [];
        $development = is_array($artifacts['DEVELOPMENT_RESULT']['content'] ?? null) ? $artifacts['DEVELOPMENT_RESULT']['content'] : [];
        $review = is_array($artifacts['REVIEW_REPORT']['content'] ?? null) ? $artifacts['REVIEW_REPORT']['content'] : [];
        $qa = is_array($artifacts['QA_REPORT']['content'] ?? null) ? $artifacts['QA_REPORT']['content'] : [];

        $architectureRevision = trim((string) ($architecture['repository_revision'] ?? ''));
        $developmentRevision = trim((string) ($development['repository_revision'] ?? ''));
        $reviewRevision = trim((string) ($review['reviewed_revision'] ?? ''));
        $qaRevision = trim((string) ($qa['tested_revision'] ?? ''));
        $qaMutationRevision = '';

        foreach ($runs as $run) {
            if (!is_array($run) || ($run['role'] ?? null) !== 'QA') continue;
            $output = is_array($run['output'] ?? null) ? $run['output'] : [];
            if (($output['status'] ?? null) !== 'TESTS_UPDATED') continue;
            $candidate = trim((string) ($output['repository_revision_after_tests'] ?? ''));
            if ($candidate !== '') $qaMutationRevision = $candidate;
        }

        $expectedReviewedRevision = $qaMutationRevision !== '' ? $qaMutationRevision : $developmentRevision;
        $passed = $architectureRevision !== ''
            && $developmentRevision !== ''
            && $expectedReviewedRevision !== ''
            && $reviewRevision === $expectedReviewedRevision
            && $qaRevision === $reviewRevision;

        return $this->check(
            'revision_chain_consistent',
            $passed,
            sprintf(
                'architecture=%s; development=%s; qa-test-revision=%s; reviewed=%s; tested=%s.',
                $architectureRevision ?: 'missing',
                $developmentRevision ?: 'missing',
                $qaMutationRevision ?: 'n/a',
                $reviewRevision ?: 'missing',
                $qaRevision ?: 'missing',
            ),
        );
    }

    /** @param array<string,mixed> $artifacts @param list<array<string,mixed>> $runs */
    private function pullRequestCheck(array $artifacts, array $runs): array
    {
        $numbers = [];
        foreach (['DEVELOPMENT_RESULT', 'REVIEW_REPORT', 'QA_REPORT', 'FINAL_REPORT'] as $type) {
            $content = is_array($artifacts[$type]['content'] ?? null) ? $artifacts[$type]['content'] : [];
            $candidate = $type === 'FINAL_REPORT'
                ? ($content['pull_request']['number'] ?? null)
                : ($content['pull_request'] ?? null);
            $number = (int) $candidate;
            if ($number > 0) $numbers[$number] = true;
        }
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $output = is_array($run['output'] ?? null) ? $run['output'] : [];
            $number = (int) ($output['pull_request'] ?? 0);
            if ($number > 0) $numbers[$number] = true;
        }

        $passed = count($numbers) === 1;
        return $this->check(
            'single_pull_request',
            $passed,
            $passed ? 'Exactly one PR is referenced throughout the feature.' : 'Referenced PRs: '.(implode(', ', array_keys($numbers)) ?: 'none').'.',
        );
    }

    /** @param list<array{id:string,passed:bool,detail:string}> $checks @param list<array<string,mixed>> $runs @param list<array<string,mixed>> $audit */
    private function appendFixLoopChecks(array &$checks, array $runs, array $audit): void
    {
        $defectDetected = false;
        $developerRuns = 0;
        $reviewerRuns = 0;
        foreach ($runs as $run) {
            if (!is_array($run)) continue;
            $role = (string) ($run['role'] ?? '');
            $output = is_array($run['output'] ?? null) ? $run['output'] : [];
            if ($role === 'DEVELOPER') ++$developerRuns;
            if ($role === 'REVIEWER') {
                ++$reviewerRuns;
                if (($output['status'] ?? null) === 'REQUEST_CHANGES') $defectDetected = true;
            }
            if ($role === 'QA' && ($output['phase'] ?? null) === 'EXECUTION' && ($output['status'] ?? null) === 'FAIL') $defectDetected = true;
        }

        $checks[] = $this->check('fix_loop_defect_detected', $defectDetected, $defectDetected ? 'Reviewer/QA rejected at least one implementation revision.' : 'No REQUEST_CHANGES or QA FAIL evidence found.');
        $checks[] = $this->check('fix_loop_developer_rerun', $developerRuns >= 2, 'Developer runs='.$developerRuns.'.');
        $checks[] = $this->check('fix_loop_reviewer_rerun', $reviewerRuns >= 2, 'Reviewer runs='.$reviewerRuns.'.');

        $failureIndex = null;
        foreach ($audit as $index => $transition) {
            if (!is_array($transition)) continue;
            if (in_array((string) ($transition['to'] ?? ''), ['CHANGES_REQUESTED', 'QA_FAILED'], true)) {
                $failureIndex = (int) $index;
                break;
            }
        }
        $ordered = $failureIndex !== null && $this->containsOrderedTargetsAfter($audit, $failureIndex, ['DEVELOPMENT_RUNNING', 'REVIEW_PENDING', 'QA_PENDING', 'READY_FOR_HUMAN_APPROVAL']);
        $checks[] = $this->check('fix_loop_full_rerun_chain', $ordered, $ordered ? 'Developer → Reviewer → QA rerun chain reached READY.' : 'Full rerun chain after rejection was not found in audit history.');
    }

    /** @param list<array{id:string,passed:bool,detail:string}> $checks @param list<array<string,mixed>> $audit @param list<array<string,mixed>> $humanDecisions */
    private function appendHumanGateChecks(array &$checks, array $audit, array $humanDecisions): void
    {
        $answered = array_values(array_filter($humanDecisions, static fn (mixed $decision): bool => is_array($decision) && ($decision['status'] ?? null) === 'ANSWERED'));
        $checks[] = $this->check('human_gate_answered', $answered !== [], $answered !== [] ? count($answered).' human decision(s) answered.' : 'No answered human decision found.');

        $gateIndex = null;
        $resumeState = '';
        foreach ($audit as $index => $transition) {
            if (!is_array($transition) || ($transition['to'] ?? null) !== 'HUMAN_DECISION_REQUIRED') continue;
            $gateIndex = (int) $index;
            $resumeState = (string) ($transition['from'] ?? '');
            break;
        }

        $resumed = false;
        if ($gateIndex !== null && $resumeState !== '') {
            for ($i = $gateIndex + 1, $count = count($audit); $i < $count; ++$i) {
                $transition = $audit[$i];
                if (!is_array($transition)) continue;
                if (($transition['from'] ?? null) !== 'HUMAN_DECISION_REQUIRED') continue;
                $resumed = ($transition['to'] ?? null) === $resumeState && trim((string) ($transition['human_decision_id'] ?? '')) !== '';
                break;
            }
        }
        $checks[] = $this->check('human_gate_resume_exact_stage', $resumed, $resumed ? 'Workflow resumed the exact pre-gate state with decision evidence.' : 'Exact-stage human resume evidence is missing.');

        $workflowIds = [];
        foreach ($audit as $transition) {
            if (!is_array($transition)) continue;
            $id = trim((string) ($transition['workflow_id'] ?? ''));
            if ($id !== '') $workflowIds[$id] = true;
        }
        $checks[] = $this->check('human_gate_single_workflow', count($workflowIds) === 1, 'Workflow ids in audit='.count($workflowIds).'.');
    }

    /** @param list<array{id:string,passed:bool,detail:string}> $checks @param array<string,mixed> $feature @param array<string,mixed> $workflow @param list<array<string,mixed>> $audit */
    private function appendRecoveryChecks(array &$checks, array $feature, array $workflow, array $audit): void
    {
        $events = [];
        foreach (is_array($feature['previous_context'] ?? null) ? $feature['previous_context'] : [] as $entry) {
            if (!is_array($entry) || !is_array($entry['engineering_recovery'] ?? null)) continue;
            $events[] = $entry['engineering_recovery'];
        }

        $workflowId = (string) ($workflow['id'] ?? '');
        $valid = false;
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            if (($event['workflow_id'] ?? null) !== $workflowId) continue;
            if (!in_array((string) ($event['state'] ?? ''), self::RESUMABLE_STATES, true)) continue;
            if (trim((string) ($event['correlation_id'] ?? '')) === '') continue;
            if ((int) ($event['recovered_stale_runs'] ?? 0) < 1) continue;
            $valid = true;
            break;
        }
        $checks[] = $this->check('recovery_continue_evidence', $valid, $valid ? 'Persisted cos:engineering:continue evidence exists for this workflow.' : 'No valid recovery continuation evidence found.');

        $workflowIds = [];
        foreach ($audit as $transition) {
            if (!is_array($transition)) continue;
            $id = trim((string) ($transition['workflow_id'] ?? ''));
            if ($id !== '') $workflowIds[$id] = true;
        }
        $checks[] = $this->check('recovery_single_workflow', count($workflowIds) === 1, 'Workflow ids in audit='.count($workflowIds).'.');
    }

    /** @param list<array<string,mixed>> $audit @param list<string> $targets */
    private function containsOrderedTargetsAfter(array $audit, int $afterIndex, array $targets): bool
    {
        $targetIndex = 0;
        for ($i = $afterIndex + 1, $count = count($audit); $i < $count && $targetIndex < count($targets); ++$i) {
            $transition = $audit[$i];
            if (!is_array($transition)) continue;
            if (($transition['to'] ?? null) === $targets[$targetIndex]) ++$targetIndex;
        }
        return $targetIndex === count($targets);
    }

    /** @return array{id:string,passed:bool,detail:string} */
    private function check(string $id, bool $passed, string $detail): array
    {
        return ['id' => $id, 'passed' => $passed, 'detail' => $detail];
    }
}
