<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\Audit\EngineeringAuditQueryInterface;
use App\Engineering\Application\Observability\EngineeringObservabilityReadModelInterface;
use App\Engineering\Application\Persistence\EngineeringAgentRunStoreInterface;
use App\Engineering\Application\Persistence\EngineeringArtifactStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringFindingStoreInterface;
use App\Engineering\Application\Persistence\EngineeringHumanDecisionStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use App\Engineering\Domain\Artifact\ArtifactType;

final readonly class EngineeringStatusService
{
    public function __construct(
        private EngineeringFeatureStoreInterface $features,
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringTaskStoreInterface $tasks,
        private EngineeringAgentRunStoreInterface $agentRuns,
        private EngineeringArtifactStoreInterface $artifacts,
        private EngineeringFindingStoreInterface $findings,
        private EngineeringHumanDecisionStoreInterface $humanDecisions,
        private EngineeringAuditQueryInterface $audit,
        private EngineeringObservabilityReadModelInterface $observability,
    ) {}

    public function status(string $featureId): array
    {
        $feature = $this->features->view($featureId);
        $workflowId = $this->workflows->latestIdForFeature($featureId);
        $workflow = $workflowId !== null ? $this->workflows->view($workflowId) : null;

        $artifactViews = [];
        foreach (ArtifactType::cases() as $type) {
            $artifact = $this->artifacts->latest($featureId, $type);
            if ($artifact === null) continue;
            $artifactViews[$type->value] = $artifact;
        }

        $tasks = $this->tasks->forFeature($featureId);
        $agentRuns = $workflowId !== null ? $this->agentRuns->forWorkflow($workflowId) : [];
        $findings = $this->findings->forFeature($featureId);
        $transitions = array_values(array_filter(
            $this->audit->forFeature($featureId),
            static fn (array $transition): bool => $workflowId === null || ($transition['workflow_id'] ?? null) === $workflowId,
        ));
        $usage = $workflowId !== null
            ? $this->observability->usageForWorkflow($workflowId)
            : $this->observability->usageForFeature($featureId);
        $invocations = $workflowId !== null
            ? $this->observability->invocationsForWorkflow($workflowId)
            : $this->observability->invocationsForFeature($featureId);

        return [
            'feature' => $feature,
            'workflow' => $workflow,
            'tasks' => $tasks,
            'agent_runs' => $agentRuns,
            'artifacts' => $artifactViews,
            'findings' => $findings,
            'open_human_decisions' => $this->humanDecisions->openForFeature($featureId),
            'transitions' => $transitions,
            'usage' => $usage,
            'llm_invocations' => $invocations,
            'timeline' => $this->timeline($transitions, $agentRuns, $invocations, $tasks, $artifactViews, $findings),
        ];
    }
    /**
     * Build one operational event stream from persisted Engineering truth.
     *
     * @param list<array<string,mixed>> $transitions
     * @param list<array<string,mixed>> $agentRuns
     * @param list<array<string,mixed>> $invocations
     * @param list<array<string,mixed>> $tasks
     * @param array<string,array<string,mixed>> $artifacts
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function timeline(
        array $transitions,
        array $agentRuns,
        array $invocations,
        array $tasks,
        array $artifacts,
        array $findings,
    ): array {
        $events = [];

        foreach ($transitions as $transition) {
            $events[] = [
                'time' => (string) ($transition['when'] ?? ''),
                'type' => 'WORKFLOW',
                'status' => (string) ($transition['to'] ?? ''),
                'title' => (string) ($transition['from'] ?? '?').' → '.(string) ($transition['to'] ?? '?'),
                'detail' => (string) ($transition['reason'] ?? $transition['trigger'] ?? ''),
                'actor' => (string) ($transition['who']['id'] ?? $transition['who']['type'] ?? 'system'),
                'correlation_id' => null,
                'reference_id' => (string) ($transition['id'] ?? ''),
            ];
        }

        foreach ($agentRuns as $run) {
            $role = (string) ($run['role'] ?? 'AGENT');
            $events[] = [
                'time' => (string) ($run['started_at'] ?? ''),
                'type' => 'AGENT',
                'status' => 'RUNNING',
                'title' => $role.' started',
                'detail' => (string) ($run['model'] ?? 'model pending'),
                'actor' => $role,
                'correlation_id' => (string) ($run['trace_id'] ?? ''),
                'reference_id' => (string) ($run['id'] ?? ''),
            ];
            foreach (is_array($run['runtime_steps'] ?? null) ? $run['runtime_steps'] : [] as $step) {
                if (!is_array($step)) continue;
                $stepOutput = is_array($step['output'] ?? null) ? $step['output'] : [];
                $events[] = [
                    'time' => (string) ($run['finished_at'] ?? $run['started_at'] ?? ''),
                    'type' => 'STEP',
                    'status' => strtoupper((string) ($step['status'] ?? 'UNKNOWN')),
                    'title' => $role.' · step '.(string) ($step['sequence'] ?? '?').' · '.(string) ($step['type'] ?? 'runtime'),
                    'detail' => trim(implode(' · ', array_values(array_filter([
                        isset($stepOutput['provider']) ? (string) $stepOutput['provider'] : null,
                        isset($stepOutput['model']) ? (string) $stepOutput['model'] : null,
                        ($step['error'] ?? null) !== null ? 'ERROR: '.(string) $step['error'] : null,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),))),
                    'actor' => $role,
                    'correlation_id' => (string) ($run['trace_id'] ?? ''),
                    'reference_id' => (string) ($step['id'] ?? ''),
                ];
            }

            if (($run['finished_at'] ?? null) !== null) {
                $tokens = (($run['tokens_input'] ?? null) !== null || ($run['tokens_output'] ?? null) !== null)
                    ? (int) ($run['tokens_input'] ?? 0) + (int) ($run['tokens_output'] ?? 0)
                    : null;
                $detail = trim(implode(' · ', array_values(array_filter([
                    (string) ($run['provider'] ?? '').'/'.(string) ($run['model'] ?? ''),
                    $tokens !== null ? number_format($tokens, 0, '.', ' ').' tokens' : null,
                    ($run['cost'] ?? null) !== null ? '.number_format((float) $run['cost'], 4, '.', '') : null,
                    ($run['error_message'] ?? null) !== null ? 'ERROR: '.(string) $run['error_message'] : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),)));
                $events[] = [
                    'time' => (string) $run['finished_at'],
                    'type' => 'AGENT',
                    'status' => (string) ($run['status'] ?? 'UNKNOWN'),
                    'title' => $role.' finished',
                    'detail' => $detail,
                    'actor' => $role,
                    'correlation_id' => (string) ($run['trace_id'] ?? ''),
                    'reference_id' => (string) ($run['id'] ?? ''),
                ];
            }
        }

        foreach ($invocations as $invocation) {
            $tokens = $invocation['total_tokens'] ?? null;
            $cost = $invocation['cost_amount'] ?? null;
            $events[] = [
                'time' => (string) ($invocation['created_at'] ?? ''),
                'type' => 'LLM',
                'status' => 'COMPLETED',
                'title' => trim((string) ($invocation['provider'] ?? '')).' / '.trim((string) ($invocation['model'] ?? '')),
                'detail' => trim(implode(' · ', array_values(array_filter([
                    $invocation['use_case'] ?? null,
                    $tokens !== null ? number_format((int) $tokens, 0, '.', ' ').' tokens' : null,
                    $cost !== null ? ((string) ($invocation['cost_currency'] ?? 'USD')).' '.number_format((float) $cost, 4, '.', '') : 'cost unavailable',
                    ($invocation['latency_ms'] ?? null) !== null ? (int) $invocation['latency_ms'].' ms' : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),))),
                'actor' => 'LLM',
                'correlation_id' => (string) ($invocation['correlation_id'] ?? ''),
                'reference_id' => (string) ($invocation['id'] ?? ''),
            ];
        }

        foreach ($tasks as $task) {
            $events[] = [
                'time' => (string) ($task['updated_at'] ?? $task['created_at'] ?? ''),
                'type' => 'TASK',
                'status' => (string) ($task['status'] ?? ''),
                'title' => (string) ($task['external_key'] ?? 'Task').' · '.(string) ($task['title'] ?? ''),
                'detail' => (string) ($task['assigned_role'] ?? ''),
                'actor' => (string) ($task['assigned_role'] ?? 'system'),
                'correlation_id' => null,
                'reference_id' => (string) ($task['id'] ?? ''),
            ];
        }

        foreach ($artifacts as $type => $artifact) {
            if (!is_array($artifact) || trim((string) ($artifact['created_at'] ?? '')) === '') continue;
            $events[] = [
                'time' => (string) $artifact['created_at'],
                'type' => 'ARTIFACT',
                'status' => (string) ($artifact['status'] ?? 'ACTIVE'),
                'title' => (string) $type.' v'.(string) ($artifact['version'] ?? '?'),
                'detail' => 'Created by '.(string) ($artifact['created_by_agent'] ?? 'system'),
                'actor' => (string) ($artifact['created_by_agent'] ?? 'system'),
                'correlation_id' => null,
                'reference_id' => (string) ($artifact['id'] ?? ''),
            ];
        }

        foreach ($findings as $finding) {
            $events[] = [
                'time' => (string) ($finding['created_at'] ?? ''),
                'type' => 'FINDING',
                'status' => (string) ($finding['status'] ?? ''),
                'title' => (string) ($finding['severity'] ?? '').' · '.(string) ($finding['title'] ?? 'Finding'),
                'detail' => (string) ($finding['description'] ?? ''),
                'actor' => (string) ($finding['source_role'] ?? 'system'),
                'correlation_id' => null,
                'reference_id' => (string) ($finding['id'] ?? ''),
            ];
        }

        usort($events, static function (array $a, array $b): int {
            $aTime = strtotime((string) ($a['time'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['time'] ?? '')) ?: 0;
            if ($aTime === $bTime) return strcmp((string) ($b['reference_id'] ?? ''), (string) ($a['reference_id'] ?? ''));
            return $bTime <=> $aTime;
        });

        return array_slice($events, 0, 250);
    }
}
