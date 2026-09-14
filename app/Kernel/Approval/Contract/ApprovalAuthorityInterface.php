<?php
declare(strict_types=1);

namespace Kernel\Approval\Contract;

use Kernel\Action\Action;
use Kernel\Approval\Approval;

interface ApprovalAuthorityInterface
{
    public function assertCanDecide(string $organizationId, string $userId, Approval $approval, Action $action): void;
}
