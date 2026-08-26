<?php
declare(strict_types=1);

namespace Kernel\Approval\Service;

use DomainException;
use Kernel\Action\Service\ActionService;
use Kernel\Approval\ApprovalStatus;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use DateTimeImmutable;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ApprovalService
{
    public function __construct(
        private ApprovalRepositoryInterface $approvals,
        private ActionService $actions,
        private TransactionManagerInterface $transactions,
        private ?AuditRepositoryInterface $audit = null,
        private ?JobQueueInterface $queue = null,
    ) {}

    public function approve(string $organizationId, string $approvalId, string $userId, ?string $note = null): void
    {
        $this->decide($organizationId, $approvalId, $userId, ApprovalStatus::Approved, $note);
    }

    public function reject(string $organizationId, string $approvalId, string $userId, ?string $note = null): void
    {
        $this->decide($organizationId, $approvalId, $userId, ApprovalStatus::Rejected, $note);
    }

    private function decide(string $organizationId, string $approvalId, string $userId, ApprovalStatus $decision, ?string $note): void
    {
        $this->transactions->transactional(function () use ($organizationId, $approvalId, $userId, $decision, $note): void {
            $approval = $this->approvals->findPending($organizationId, $approvalId);
            if ($approval === null || !$this->approvals->decide($organizationId, $approvalId, $decision, $userId, $note)) {
                throw new DomainException('Approval is not pending or does not exist.');
            }
            $action = null;
            if ($decision === ApprovalStatus::Approved) {
                $this->actions->queue($organizationId, $approval->actionId);
                $action = $this->actions->find($organizationId, $approval->actionId);
                $this->queue?->enqueue(
                    $organizationId,
                    'ACTION_EXECUTION',
                    ['action_id' => $approval->actionId],
                    $action?->correlationId ?: $approval->actionId,
                    'action-execution:' . $approval->actionId,
                    5,
                    120,
                );
            } else {
                $this->actions->reject($organizationId, $approval->actionId);
            }
            $action ??= $this->actions->find($organizationId, $approval->actionId);
            $this->audit?->append(new AuditEntry(
                bin2hex(random_bytes(16)), $organizationId, 'APPROVAL', 'USER', $userId,
                'action', $approval->actionId, $note,
                [
                    'action' => 'approval.' . strtolower($decision->value),
                    'input_references' => ['approval_id' => $approvalId, 'action_id' => $approval->actionId],
                    'result' => ['status' => $decision->value],
                ],
                $action?->correlationId ?: $approval->actionId, new DateTimeImmutable(),
            ));
        });
    }
}
