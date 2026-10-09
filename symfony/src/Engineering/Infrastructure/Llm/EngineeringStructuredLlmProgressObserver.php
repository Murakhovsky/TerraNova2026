<?php
declare(strict_types=1);

namespace App\Engineering\Infrastructure\Llm;

use App\Engineering\Application\Observability\EngineeringExecutionJournal;
use App\Engineering\Application\Persistence\EngineeringWorkflowStoreInterface;
use Kernel\Llm\StructuredLlmProgressObserverInterface;
use Kernel\Llm\StructuredLlmRequest;
use Throwable;

final readonly class EngineeringStructuredLlmProgressObserver implements StructuredLlmProgressObserverInterface
{
    public function __construct(
        private EngineeringWorkflowStoreInterface $workflows,
        private EngineeringExecutionJournal $journal,
    ) {}

    public function progress(
        StructuredLlmRequest $request,
        string $provider,
        string $providerRequestId,
        string $status,
        int $pollCount,
    ): void {
        if (!str_starts_with((string) $request->useCase, 'agent.')) {
            return;
        }

        $metadata = is_array($request->context['metadata'] ?? null)
            ? $request->context['metadata']
            : [];
        $featureId = trim((string) ($metadata['engineering_feature_id'] ?? ''));
        if ($featureId === '') {
            return;
        }

        try {
            $workflowId = $this->workflows->activeIdForFeature($featureId)
                ?? $this->workflows->latestIdForFeature($featureId);
            if ($workflowId === null || $workflowId === '') {
                return;
            }

            $workflow = $this->workflows->view($workflowId);
            $currentAgentRunId = trim((string) ($workflow['current_agent_run_id'] ?? ''));

            // engineering_task_id is an execution correlation id, not a persisted
            // cos_engineering_tasks.id. Passing it as current_task_id violates the FK,
            // made every background-poll heartbeat fail, and the exception was deliberately
            // swallowed here to avoid breaking the provider call. Attribute liveness to the
            // persisted current AgentRun instead.
            $this->workflows->touchRuntime(
                $workflowId,
                $currentAgentRunId !== '' ? $currentAgentRunId : null,
                null,
            );

            if ($pollCount === 0 || in_array($status, ['completed','failed','cancelled','incomplete'], true)) {
                $terminal = in_array($status, ['completed','failed','cancelled','incomplete'], true);
                $this->journal->event(
                    $featureId,
                    $workflowId,
                    'LLM',
                    'llm.background_status',
                    $terminal ? strtoupper($status) : 'RUNNING',
                    $terminal
                        ? 'Background LLM response finished with status '.$status.'.'
                        : 'Background LLM response accepted; polling every 20 seconds.',
                    $request->correlationId ?? 'engineering:llm:background',
                    [
                        'provider' => $provider,
                        'provider_request_id' => $providerRequestId,
                        'provider_status' => $status,
                        'poll_count' => $pollCount,
                        'poll_interval_seconds' => 20,
                    ],
                );
            }
        } catch (Throwable) {
            // Progress reporting must never fail the underlying provider call.
        }
    }
}
