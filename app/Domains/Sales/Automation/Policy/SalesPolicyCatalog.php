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
            new ActionPolicy($this->id($organizationId, 'sales-auto-qualification-task-v1'), $organizationId, 'sales.create_qualification_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'sales-auto-followup-task-v1'), $organizationId, 'sales.create_followup_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'sales-auto-overdue-escalation-v1'), $organizationId, 'sales.escalate_overdue_followup', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-send-followup-auto-v1'), $organizationId, 'sales.send_followup', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-create-task-auto-v1'), $organizationId, 'sales.create_task', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-schedule-followup-auto-v1'), $organizationId, 'sales.schedule_followup', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-send-message-approval-v1'), $organizationId, 'sales.send_message', [], PolicyDecision::ApprovalRequired, 10),
            new ActionPolicy($this->id($organizationId, 'policy-financing-followup-approval-v1'), $organizationId, 'sales.send_financing_followup', [], PolicyDecision::ApprovalRequired, 10),
            new ActionPolicy($this->id($organizationId, 'policy-manager-review-auto-v1'), $organizationId, 'sales.request_manager_review', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-update-deal-auto-v1'), $organizationId, 'sales.update_deal', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-create-followup-auto-v1'), $organizationId, 'sales.create_followup', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-change-stage-auto-v1'), $organizationId, 'sales.change_stage', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-assign-owner-auto-v1'), $organizationId, 'sales.assign_owner', [], PolicyDecision::Auto, 10),
            new ActionPolicy($this->id($organizationId, 'policy-request-document-approval-v1'), $organizationId, 'sales.request_document', [], PolicyDecision::ApprovalRequired, 10),
            new ActionPolicy($this->id($organizationId, 'policy-schedule-meeting-auto-v1'), $organizationId, 'sales.schedule_meeting', [], PolicyDecision::Auto, 10),
        ];
    }

    private function id(string $organizationId, string $code): string
    {
        return $organizationId === 'default'
            ? $code
            : substr(hash('sha256', $organizationId . ':policy:' . $code), 0, 32);
    }
}
