<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Handler;

use Domains\Diagnostic\Application\Contract\DiagnosticRuntimeRepositoryInterface;
use Kernel\Action\Event\ActionExecutionFinished;
use Kernel\Event\Contract\DurableEventConsumerInterface;
use Kernel\Event\DomainEvent;

final readonly class DiagnosticActionOutcomeHandler implements DurableEventConsumerInterface
{
    public function __construct(private DiagnosticRuntimeRepositoryInterface $runtime) {}

    public function consumerName(): string { return 'diagnostic.action-outcome.v1'; }

    public function handle(DomainEvent $event): void
    {
        if ($event->type !== ActionExecutionFinished::COMPLETED
            || ($event->payload['action_type'] ?? '') !== 'IMPLEMENT_DIAGNOSTIC_RECOMMENDATION'
        ) {
            return;
        }

        $sessionId = $event->metadata->correlationId;
        $actionId = (string) ($event->payload['action_id'] ?? '');
        if ($sessionId === '' || $actionId === '') return;

        // Diagnostic sessions are immutable after completion. Post-diagnostic outcomes therefore
        // live in the runtime measurement ledger instead of mutating terminal session evidence.
        $evidenceId = 'action-result-' . $actionId;
        foreach ($event->payload['metrics'] ?? [] as $metric => $value) {
            if (is_numeric($value)) {
                $this->runtime->appendMeasurement(
                    $event->organizationId,
                    $sessionId,
                    $actionId,
                    (string) $metric,
                    (float) $value,
                    $evidenceId,
                    $event->occurredAt,
                );
            }
        }

        $recommendation = $this->runtime->recommendation($event->organizationId, $sessionId, $event->aggregateId);
        if ($recommendation !== null) {
            $payload = $recommendation['payload'];
            $payload['status'] = 'IMPLEMENTED';
            $payload['last_action_output'] = $event->payload['output'] ?? [];
            $this->runtime->saveRecommendation(
                $event->organizationId,
                $sessionId,
                $event->aggregateId,
                $payload,
                'IMPLEMENTED',
                $event->occurredAt,
                $actionId,
            );
        }
    }
}
