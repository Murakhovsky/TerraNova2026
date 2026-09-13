<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\CrmInboundApplierInterface;
use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Automation\Event\SalesEventType;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Module\DomainModuleRegistry;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use RuntimeException;
use Throwable;

final readonly class ProcessCrmInbox
{
    public function __construct(
        private CrmInboxRepositoryInterface $inbox,
        private CrmInboundApplierInterface $applier,
        private DomainModuleRegistry $domains,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private ?PipelineRepositoryInterface $pipelines = null,
        private ?ChangeDealStage $changeDealStage = null,
        private ?SalesOperationService $operations = null,
    ) {
    }

    public function execute(string $organizationId, string $inboxId, string $workerId): void
    {
        $item = $this->inbox->claim($organizationId, $inboxId, $workerId);
        if ($item === null) return;

        try {
            $this->transactions->transactional(function () use ($item): void {
                $applied = $this->applier->apply($item);
                $eventType = trim((string) ($applied['event_type'] ?? ''));
                $aggregateType = trim((string) ($applied['aggregate_type'] ?? ''));
                $aggregateId = trim((string) ($applied['aggregate_id'] ?? ''));
                $payload = is_array($applied['payload'] ?? null) ? $applied['payload'] : [];

                if ($eventType === '' || !$this->domains->ownsEvent('sales', $eventType)) {
                    throw new RuntimeException('Mapped CRM event is not owned by Sales: ' . ($eventType !== '' ? $eventType : '[empty]'));
                }
                if ($aggregateType === '' || $aggregateId === '') {
                    throw new RuntimeException('Mapped CRM aggregate identity is incomplete.');
                }

                $stageHandled = false;
                $messageHandled = false;

                if (($payload['requested_message'] ?? false) === true) {
                    if ($this->operations === null) {
                        throw new RuntimeException('Incoming message service is unavailable for CRM ingress.');
                    }
                    $message = $this->operations->recordIncomingMessage(
                        $item->organizationId,
                        $aggregateId,
                        (string) ($payload['channel'] ?? 'WEB'),
                        (string) ($payload['sender'] ?? ''),
                        (string) ($payload['recipient'] ?? ''),
                        (string) ($payload['body'] ?? ''),
                        (string) ($payload['external_id'] ?? ''),
                        $item->correlationId,
                        ['provider' => $item->provider],
                    );
                    if (!$message->successful) {
                        throw new RuntimeException($message->error ?? 'Incoming message persistence failed.');
                    }
                    unset($payload['requested_message']);
                    $messageHandled = $eventType === SalesEventType::MESSAGE_RECEIVED;
                }

                $requestedStage = $payload['requested_stage'] ?? null;
                if ($aggregateType === 'deal' && $requestedStage !== null && $requestedStage !== '') {
                    if ($this->pipelines === null || $this->changeDealStage === null) {
                        throw new RuntimeException('Canonical stage service is unavailable for CRM ingress.');
                    }
                    $stage = $this->pipelines->getStage($item->organizationId, (string) $requestedStage);
                    if ($stage === null) {
                        $casePipelineId = (string) ($payload['pipeline_id'] ?? '');
                        if ($casePipelineId === '') {
                            $casePipelineId = $this->pipelines->getDefaultPipeline($item->organizationId)?->id ?? '';
                        }
                        $stage = $this->pipelines->findStageByCode(
                            $item->organizationId,
                            $casePipelineId,
                            strtoupper((string) $requestedStage),
                        );
                    }
                    if ($stage === null) {
                        throw new RuntimeException('CRM stage cannot be resolved in the organization pipeline.');
                    }
                    $result = $this->changeDealStage->execute(new ChangeDealStageCommand(
                        $item->organizationId,
                        $aggregateId,
                        $stage->id,
                        'INTEGRATION',
                        $item->provider,
                        $item->correlationId,
                    ));
                    if (!$result->successful) {
                        throw new RuntimeException($result->reason ?? 'CRM stage transition failed.');
                    }
                    unset($payload['requested_stage']);
                    $stageHandled = $eventType === 'sales.deal.stage_changed';
                }

                if (!$stageHandled && !$messageHandled) {
                    $this->events->publish(new DomainEvent(
                        bin2hex(random_bytes(16)),
                        $item->organizationId,
                        $eventType,
                        $aggregateType,
                        $aggregateId,
                        $payload,
                        new EventMetadata($item->correlationId, null, 'INTEGRATION', $item->provider),
                        new DateTimeImmutable(),
                    ));
                }
                $this->inbox->complete($item);
            });
        } catch (Throwable $error) {
            try {
                $this->inbox->fail($item, $error);
            } catch (Throwable) {
                // Preserve the processing failure; failure persistence must not mask the root cause.
            }
            throw $error;
        }
    }
}
