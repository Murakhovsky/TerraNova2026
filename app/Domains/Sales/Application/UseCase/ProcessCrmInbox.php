<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\CrmInboundApplierInterface;
use Domains\Sales\Application\Contract\CrmInboxRepositoryInterface;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\Service\SalesOperationService;
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
                $stageHandled = false;
                $messageHandled = false;
                if (($applied['payload']['requested_message'] ?? false) === true) {
                    if ($this->operations === null) throw new RuntimeException('Incoming message service is unavailable for CRM ingress.');
                    $message = $this->operations->recordIncomingMessage(
                        $item->organizationId, (string) $applied['aggregate_id'], (string) ($applied['payload']['channel'] ?? 'WEB'),
                        (string) ($applied['payload']['sender'] ?? ''), (string) ($applied['payload']['recipient'] ?? ''),
                        (string) $applied['payload']['body'], (string) $applied['payload']['external_id'], $item->correlationId,
                        ['provider' => $item->provider],
                    );
                    if (!$message->successful) throw new RuntimeException($message->error ?? 'Incoming message persistence failed.');
                    $messageHandled = true;
                }
                $requestedStage = $applied['payload']['requested_stage'] ?? null;
                if ($applied['aggregate_type'] === 'deal' && $requestedStage !== null && $requestedStage !== '') {
                    if ($this->pipelines === null || $this->changeDealStage === null) throw new RuntimeException('Canonical stage service is unavailable for CRM ingress.');
                    $stage = $this->pipelines->getStage($item->organizationId, (string) $requestedStage);
                    if ($stage === null) {
                        $casePipelineId = (string) ($applied['payload']['pipeline_id'] ?? '');
                        if ($casePipelineId === '') $casePipelineId = $this->pipelines->getDefaultPipeline($item->organizationId)?->id ?? '';
                        $stage = $this->pipelines->findStageByCode($item->organizationId, $casePipelineId, strtoupper((string) $requestedStage));
                    }
                    if ($stage === null) throw new RuntimeException('CRM stage cannot be resolved in the organization pipeline.');
                    $result = $this->changeDealStage->execute(new ChangeDealStageCommand(
                        $item->organizationId, (string) $applied['aggregate_id'], $stage->id,
                        'INTEGRATION', $item->provider, $item->correlationId,
                    ));
                    if (!$result->successful) throw new RuntimeException($result->reason ?? 'CRM stage transition failed.');
                    unset($applied['payload']['requested_stage']);
                    $stageHandled = $applied['event_type'] === 'sales.deal.stage_changed';
                }
                if (!$this->domains->ownsEvent('sales', $applied['event_type'])) {
                    throw new RuntimeException('Mapped CRM event is not owned by Sales: ' . $applied['event_type']);
                }
                if (!$stageHandled && !$messageHandled) {
                    $eventId = bin2hex(random_bytes(16));
                    $this->events->publish(new DomainEvent(
                        $eventId, $item->organizationId, $applied['event_type'], $applied['aggregate_type'], $applied['aggregate_id'],
                        $applied['payload'], new EventMetadata($item->correlationId, null, 'INTEGRATION', $item->provider), new DateTimeImmutable(),
                    ));
                }
                $this->inbox->complete($item);
            });
        } catch (Throwable $error) {
            $this->inbox->fail($item, $error);
            throw $error;
        }
    }
}
