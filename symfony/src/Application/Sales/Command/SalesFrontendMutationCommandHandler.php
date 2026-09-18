<?php
declare(strict_types=1);

namespace App\Application\Sales\Command;

use App\Application\System\Command\ExecuteSalesActionCommand;
use DateTimeImmutable;
use DomainException;
use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Domains\Sales\Application\DTO\ScheduleLeadFollowupCommand;
use Domains\Sales\Application\Service\SalesOperationService;
use Domains\Sales\Application\UseCase\ScheduleLeadFollowup;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Application\Bus\CommandBusInterface;
use Kernel\Application\Command\CommandHandlerInterface;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Approval\Service\ApprovalService;
use Throwable;

final readonly class SalesFrontendMutationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private SalesWriteServiceFactoryInterface $writes,
        private SalesOperationService $operations,
        private ScheduleLeadFollowup $leadFollowups,
        private ApprovalRepositoryInterface $approvalRepository,
        private ApprovalService $approvals,
        private ActionService $actions,
        private CommandBusInterface $commands,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(SalesFrontendMutationCommand $command): array
    {
        if ($command->actorId <= 0) {
            throw new DomainException('Authenticated actor is invalid.');
        }

        return match ($command->operation) {
            SalesFrontendMutationCommand::QUICK_UPDATE => $this->quickUpdate($command),
            SalesFrontendMutationCommand::OWNER => $this->owner($command),
            SalesFrontendMutationCommand::MEETING => $this->meeting($command),
            SalesFrontendMutationCommand::COMPLETE_ACTIVITY => $this->completeActivity($command),
            SalesFrontendMutationCommand::RESCHEDULE_ACTIVITY => $this->rescheduleActivity($command),
            SalesFrontendMutationCommand::LEAD_FOLLOWUP => $this->leadFollowup($command),
            SalesFrontendMutationCommand::APPROVAL => $this->approval($command),
            SalesFrontendMutationCommand::ACTION => $this->action($command),
            default => throw new DomainException('Unsupported Sales frontend mutation.'),
        };
    }

    /** @return array<string,mixed> */
    private function quickUpdate(SalesFrontendMutationCommand $command): array
    {
        $dealId = $this->positiveInt($command->resourceId, 'Invalid opportunity id.');
        $changes = array_intersect_key($command->input, array_flip(['priority', 'next_contact_at']));
        if ($changes === []) {
            throw new DomainException('priority or next_contact_at is required.');
        }

        $result = $this->writes->forOrganization($command->organizationId->value())
            ->quickUpdateOpportunity($dealId, $changes, $command->actorId, $command->correlationId);

        if (!$result->ok) {
            throw new DomainException($result->code);
        }

        return ['code' => $result->code, ...$result->data];
    }

    /** @return array<string,mixed> */
    private function owner(SalesFrontendMutationCommand $command): array
    {
        $dealId = $this->positiveInt($command->resourceId, 'Invalid opportunity id.');
        $ownerId = (int) ($command->input['owner_id'] ?? 0);
        if ($ownerId <= 0) {
            throw new DomainException('A valid owner_id is required.');
        }

        $result = $this->writes->forOrganization($command->organizationId->value())
            ->quickUpdateOpportunity(
                $dealId,
                ['assigned_user_id' => $ownerId],
                $command->actorId,
                $command->correlationId,
            );

        if (!$result->ok) {
            throw new DomainException($result->code);
        }

        return ['owner_id' => $ownerId, 'code' => $result->code, ...$result->data];
    }

    /** @return array<string,mixed> */
    private function meeting(SalesFrontendMutationCommand $command): array
    {
        $dealId = $this->positiveInt($command->resourceId, 'Invalid opportunity id.');
        $raw = trim((string) ($command->input['scheduled_at'] ?? ''));
        try {
            $at = new DateTimeImmutable($raw);
        } catch (Throwable) {
            throw new DomainException('Valid scheduled_at is required.');
        }
        if ($at <= new DateTimeImmutable()) {
            throw new DomainException('Meeting must be scheduled in the future.');
        }

        $key = $this->idempotencyKey($command);
        $result = $this->operations->scheduleMeeting(
            $command->organizationId->value(),
            (string) $dealId,
            mb_substr(trim((string) ($command->input['title'] ?? 'Sales meeting')) ?: 'Sales meeting', 0, 180),
            $at,
            $key,
            [
                'location' => ($location = trim((string) ($command->input['location'] ?? ''))) !== '' ? $location : null,
                'channel' => ($channel = trim((string) ($command->input['channel'] ?? ''))) !== '' ? $channel : null,
                'notes' => ($notes = trim((string) ($command->input['notes'] ?? ''))) !== '' ? $notes : null,
                'actor_type' => 'USER',
                'actor_id' => (string) $command->actorId,
                'correlation_id' => $command->correlationId,
            ],
        );
        if (!$result->successful) {
            throw new DomainException($result->error ?? 'Meeting scheduling failed.');
        }

        return ['meeting_id' => $result->externalId, ...$result->data];
    }

    /** @return array<string,mixed> */
    private function completeActivity(SalesFrontendMutationCommand $command): array
    {
        [$dealId, $activityId] = $this->dealActivityIds($command);
        $result = $this->operations->completeActivity(
            $command->organizationId->value(),
            (string) $dealId,
            $activityId,
            $command->actorId,
            'USER',
        );
        if (!$result->successful) {
            throw new DomainException($result->error ?? 'Activity was not found.');
        }

        return $result->data;
    }

    /** @return array<string,mixed> */
    private function rescheduleActivity(SalesFrontendMutationCommand $command): array
    {
        [$dealId, $activityId] = $this->dealActivityIds($command);
        try {
            $dueAt = new DateTimeImmutable(trim((string) ($command->input['due_at'] ?? '')));
        } catch (Throwable) {
            throw new DomainException('Valid due_at is required.');
        }

        $result = $this->operations->rescheduleActivity(
            $command->organizationId->value(),
            (string) $dealId,
            $activityId,
            $dueAt,
        );
        if (!$result->successful) {
            throw new DomainException($result->error ?? 'Activity rescheduling failed.');
        }

        return $result->data;
    }

    /** @return array<string,mixed> */
    private function leadFollowup(SalesFrontendMutationCommand $command): array
    {
        $leadId = $this->positiveInt($command->resourceId, 'Invalid lead id.');
        try {
            $dueAt = new DateTimeImmutable(trim((string) ($command->input['due_at'] ?? '')));
        } catch (Throwable) {
            throw new DomainException('Valid due_at is required.');
        }
        if ($dueAt <= new DateTimeImmutable()) {
            throw new DomainException('Follow-up must be scheduled in the future.');
        }

        $result = $this->leadFollowups->execute(
            new ScheduleLeadFollowupCommand(
                $command->organizationId->value(),
                (string) $leadId,
                mb_substr(trim((string) ($command->input['title'] ?? 'Lead follow-up')) ?: 'Lead follow-up', 0, 180),
                ($body = trim((string) ($command->input['body'] ?? ''))) !== '' ? mb_substr($body, 0, 4000) : null,
                $dueAt,
                $this->idempotencyKey($command),
            ),
            $command->correlationId,
            'USER',
            (string) $command->actorId,
        );
        if (!$result->successful) {
            throw new DomainException($result->error ?? 'Lead follow-up scheduling failed.');
        }

        return ['activity_id' => $result->externalId, ...$result->data];
    }

    /** @return array<string,mixed> */
    private function approval(SalesFrontendMutationCommand $command): array
    {
        $approvalId = $this->hexId($command->resourceId, 'Invalid approval id.');
        $decision = strtolower(trim((string) ($command->input['decision'] ?? '')));
        if (!in_array($decision, ['approve', 'reject'], true)) {
            throw new DomainException('Invalid approval decision.');
        }

        $organizationId = $command->organizationId->value();
        $approval = $this->approvalRepository->findPending($organizationId, $approvalId);
        if ($approval === null) {
            throw new DomainException('Pending approval not found.');
        }

        $note = ($note = trim((string) ($command->input['note'] ?? ''))) !== '' ? $note : null;
        if ($decision === 'approve') {
            $this->approvals->approve($organizationId, $approvalId, (string) $command->actorId, $note);
            $this->commands->dispatch(new ExecuteSalesActionCommand($organizationId, $approval->actionId));
            return ['status' => 'APPROVED', 'action_id' => $approval->actionId];
        }

        $this->approvals->reject($organizationId, $approvalId, (string) $command->actorId, $note);
        return ['status' => 'REJECTED', 'action_id' => $approval->actionId];
    }

    /** @return array<string,mixed> */
    private function action(SalesFrontendMutationCommand $command): array
    {
        $actionId = $this->hexId($command->resourceId, 'Invalid action id.');
        $decision = strtolower(trim((string) ($command->input['decision'] ?? '')));
        if (!in_array($decision, ['execute', 'dismiss'], true)) {
            throw new DomainException('Invalid action decision.');
        }

        $organizationId = $command->organizationId->value();
        $action = $this->actions->find($organizationId, $actionId);
        if ($action === null) {
            throw new DomainException('Action not found.');
        }
        if ($decision === 'dismiss') {
            if ($action->status !== ActionStatus::Proposed) {
                throw new DomainException('Only a proposed action can be dismissed directly.');
            }
            $this->actions->reject($organizationId, $actionId);
            return ['status' => 'REJECTED'];
        }

        if ($action->status === ActionStatus::PendingApproval) {
            throw new DomainException('Action requires approval first.');
        }
        if (in_array($action->status, [ActionStatus::Completed, ActionStatus::Rejected, ActionStatus::Running], true)) {
            throw new DomainException('Action cannot be executed from its current status.');
        }
        if (in_array($action->status, [ActionStatus::Proposed, ActionStatus::Failed], true)) {
            $this->actions->queue($organizationId, $actionId);
        }
        $this->commands->dispatch(new ExecuteSalesActionCommand($organizationId, $actionId));

        return ['status' => 'QUEUED'];
    }

    /** @return array{0:int,1:int} */
    private function dealActivityIds(SalesFrontendMutationCommand $command): array
    {
        $parts = explode(':', $command->resourceId, 2);
        if (count($parts) !== 2) {
            throw new DomainException('Invalid activity resource id.');
        }

        return [
            $this->positiveInt($parts[0], 'Invalid opportunity id.'),
            $this->positiveInt($parts[1], 'Invalid activity id.'),
        ];
    }

    private function positiveInt(string $value, string $message): int
    {
        if (!ctype_digit($value) || (int) $value <= 0) {
            throw new DomainException($message);
        }
        return (int) $value;
    }

    private function hexId(string $value, string $message): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $value)) {
            throw new DomainException($message);
        }
        return $value;
    }

    private function idempotencyKey(SalesFrontendMutationCommand $command): string
    {
        $key = trim($command->idempotencyKey);
        if ($key === '' || mb_strlen($key) > 191) {
            throw new DomainException('A valid X-Idempotency-Key is required.');
        }
        return $key;
    }
}
