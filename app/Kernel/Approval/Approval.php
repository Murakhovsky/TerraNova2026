<?php
declare(strict_types=1);

namespace Kernel\Approval;

final readonly class Approval
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $actionId,
        public ApprovalStatus $status,
        public string $approverType,
        public string $approverId,
        public ?string $reason = null,
    ) {}
}
