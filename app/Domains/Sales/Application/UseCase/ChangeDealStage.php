<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use DateTimeImmutable;
use Domains\Sales\Application\Contract\DealStageRepositoryInterface;
use Domains\Sales\Application\Contract\PipelineRepositoryInterface;
use Domains\Sales\Application\DTO\ChangeDealStageCommand;
use Domains\Sales\Application\DTO\ChangeDealStageResult;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Domain\Policy\StageTransitionPolicy;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ChangeDealStage
{
    public function __construct(
        private DealStageRepositoryInterface $deals,
        private PipelineRepositoryInterface $pipelines,
        private StageTransitionPolicy $policy,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function execute(ChangeDealStageCommand $command): ChangeDealStageResult
    {
        return $this->transactions->transactional(function () use ($command): ChangeDealStageResult {
        $deal = $this->deals->getForStageChange($command->organizationId, $command->dealId);
        if ($deal === null) {
            return ChangeDealStageResult::failure('Deal was not found in the current organization.');
        }

        $pipeline = $this->pipelines->getPipeline($command->organizationId, (string) $deal['pipeline_id']);
        if ($pipeline === null) {
            return ChangeDealStageResult::failure('Deal pipeline was not found.');
        }

        $current = $this->pipelines->getStage($command->organizationId, (string) $deal['stage_id']);
        $target = $this->pipelines->getStage($command->organizationId, $command->targetStageId);
        if ($current === null || $target === null) {
            return ChangeDealStageResult::failure('Current or target stage was not found in the current organization.');
        }

        if ($command->expectedOutcome!==null && ($command->expectedOutcome==='won'?!$target->isWon:($command->expectedOutcome==='lost'?!$target->isLost:true))) return ChangeDealStageResult::failure('Target stage does not match requested outcome.');
        if ($command->expectedOutcome===null && $command->expectedStageId!==null && $target->isTerminal) return ChangeDealStageResult::failure('Terminal transition requires the outcome operation.');
        if ($command->expectedStageId!==null && $current->id!==$target->id && $current->id!==$command->expectedStageId) return ChangeDealStageResult::failure('concurrent_stage_change');

        $transition = $current->id === $target->id
            ? null
            : $this->pipelines->getTransition(
                $command->organizationId,
                $pipeline->id,
                $current->id,
                $target->id,
            );

        $validation = $this->policy->evaluate($deal, $pipeline, $current, $target, $transition);
        if (!$validation->allowed) {
            return ChangeDealStageResult::failure($validation->reason);
        }
        if ($validation->noOp) {
            return ChangeDealStageResult::success($current->id, $target->id, false);
        }

        if ($validation->requiresApproval) {
            return ChangeDealStageResult::failure('Stage transition requires independently verified canonical approval; direct execution is blocked.');
        }

        if ($target->isWon && ($deal['deal_value']===null || !is_numeric($deal['deal_value']) || (float)$deal['deal_value']<=0
            || !in_array((string)($deal['currency']??''),\Domains\Sales\Model\SalesCurrency::values(),true) || (int)($deal['assigned_user_id']??0)<1)) {
            return ChangeDealStageResult::failure('WON requires positive deal value, currency and owner.');
        }
        $lostReasonId = null;
        if ($target->isLost) {
            $lostReasonId = trim((string) $command->lostReasonId);
            if ($lostReasonId === '') return ChangeDealStageResult::failure('Explicit active lost reason is required.');
            if ($lostReasonId === '' || !$this->pipelines->isValidLostReason($command->organizationId, $pipeline->id, $lostReasonId)) {
                return ChangeDealStageResult::failure('An active lost reason is required when moving a Deal to LOST.');
            }
        }

        // The transaction already holds the current deal row lock.
            $changed = $this->deals->changeStage(
                $command->organizationId,
                $command->dealId,
                $pipeline->id,
                $current->id,
                $target->id,
                $target->code,
                $target->probabilityDefault,
                $target->isTerminal,
                $target->isWon,
                $target->isLost,
                $target->isLost ? $lostReasonId : null,
                $target->isLost ? $command->lostReasonNote : null,
            );
            if (!$changed) {
                return ChangeDealStageResult::failure('concurrent_stage_change');
            }

            $metadata = new EventMetadata(
                $command->correlationId,
                null,
                $command->actorType,
                $command->actorId,
            );

            $this->events->publish(DealStageChanged::create(
                bin2hex(random_bytes(16)),
                $command->organizationId,
                $command->dealId,
                $pipeline->id,
                $current->id,
                $current->code,
                $target->id,
                $target->code,
                $metadata,
            ));

            $terminalType = $target->isWon
                ? SalesEventType::DEAL_WON
                : ($target->isLost ? SalesEventType::DEAL_LOST : null);

            if ($terminalType !== null) {
                $currency = strtoupper(trim((string) ($deal['currency'] ?? '')));
                $ownerId = (int) ($deal['assigned_user_id'] ?? 0);
                $payload = [
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $target->id,
                    'stage_code' => $target->code,
                    'deal_value' => $deal['deal_value'] !== null ? (string) $deal['deal_value'] : null,
                    'currency' => $currency !== '' ? $currency : null,
                    'owner_id' => $ownerId > 0 ? $ownerId : null,
                ];
                if ($target->isLost) {
                    $payload['lost_reason_id'] = $lostReasonId;
                    $payload['lost_reason_note'] = $command->lostReasonNote;
                }

                $this->events->publish(new DomainEvent(
                    bin2hex(random_bytes(16)),
                    $command->organizationId,
                    $terminalType,
                    'deal',
                    $command->dealId,
                    $payload,
                    $metadata,
                    new DateTimeImmutable(),
                ));
            }

            return ChangeDealStageResult::success(
                $current->id,
                $target->id,
                true,
                $validation->requiresApproval,
            );
        });
    }
}
