<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Policy;

use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

final class SalesPolicyCatalog
{
    /** @return list<ActionPolicy> */
    public function policies(string $organizationId): array
    {
        return [
            new ActionPolicy('sales-auto-qualification-task-v1', $organizationId, 'sales.create_qualification_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy('sales-auto-followup-task-v1', $organizationId, 'sales.create_followup_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy('sales-auto-overdue-escalation-v1', $organizationId, 'sales.escalate_overdue_followup', [], PolicyDecision::Auto, 10),
        ];
    }
}
