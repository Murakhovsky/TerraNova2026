<?php
declare(strict_types=1);

namespace Kernel\Approval\Contract;

use Kernel\Action\Action;
use Kernel\Approval\Approval;
use Kernel\Approval\ApprovalStatus;

interface ApprovalRepositoryInterface
{
    public function createFor(Action $action, string $approverType, string $approverId, string $reason): Approval;
    public function findPending(string $organizationId, string $approvalId): ?Approval;
    public function decide(string $organizationId, string $approvalId, ApprovalStatus $decision, string $userId, ?string $note): bool;
}
