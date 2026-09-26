<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Policy;

use Kernel\Policy\ActionPolicy;
use Kernel\Policy\PolicyDecision;

final class GrowthPolicyCatalog
{
    /** @return list<ActionPolicy> */
    public function policies(string $organizationId):array
    {
        return array_merge(
            $this->activationPolicies($organizationId,'growth.send_message','growth-send-message-blocked-v1','growth-send-message-auto-v1','growth-send-message-approval-v1','Growth email'),
            $this->activationPolicies($organizationId,'growth.send_linkedin','growth-linkedin-blocked-v1','growth-linkedin-auto-v1','growth-linkedin-approval-v1','Growth LinkedIn'),
            $this->activationPolicies($organizationId,'growth.place_call','growth-call-blocked-v1','growth-call-auto-v1','growth-call-approval-v1','Growth call'),
        );
    }

    /** @return list<ActionPolicy> */
    private function activationPolicies(
        string $organizationId,string $actionType,string $blockedCode,string $autoCode,string $approvalCode,string $label
    ):array {
        return [
            $this->policy($organizationId,$blockedCode,$actionType,'blocked',PolicyDecision::Denied,10,$label.' blocked',$label.' is disabled by the tenant outreach activation profile.'),
            $this->policy($organizationId,$autoCode,$actionType,'auto',PolicyDecision::Auto,20,$label.' auto',$label.' may queue automatically after an explicit execution proposal.'),
            $this->policy($organizationId,$approvalCode,$actionType,'approval_required',PolicyDecision::ApprovalRequired,30,$label.' approval',$label.' requires explicit manager approval before execution.'),
        ];
    }

    private function policy(
        string $organizationId,string $code,string $actionType,string $activationMode,
        PolicyDecision $decision,int $priority,string $name,string $reason
    ):ActionPolicy {
        return new ActionPolicy(
            $this->id($organizationId,$code),
            $organizationId,
            $actionType,
            [['field'=>'growth.activation_mode','operator'=>'=','value'=>$activationMode]],
            $decision,
            $priority,
            $name,
            $reason,
        );
    }

    private function id(string $organizationId,string $code):string
    {
        return $organizationId==='default'
            ? $code
            : substr(hash('sha256',$organizationId.':policy:'.$code),0,32);
    }
}
