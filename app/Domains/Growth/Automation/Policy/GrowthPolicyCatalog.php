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
        return [
            $this->approval(
                $organizationId,'growth-send-message-approval-v1','growth.send_message',
                'Growth email approval','Pre-handoff email requires explicit approval.',
            ),
            $this->approval(
                $organizationId,'growth-linkedin-approval-v1','growth.send_linkedin',
                'Growth LinkedIn approval','Pre-handoff LinkedIn engagement requires explicit approval.',
            ),
            $this->approval(
                $organizationId,'growth-call-approval-v1','growth.place_call',
                'Growth call approval','Pre-handoff call execution requires explicit approval.',
            ),
        ];
    }

    private function approval(
        string $organizationId,string $code,string $actionType,string $name,string $description
    ):ActionPolicy {
        return new ActionPolicy(
            $this->id($organizationId,$code),
            $organizationId,
            $actionType,
            [],
            PolicyDecision::ApprovalRequired,
            10,
            $name,
            $description,
        );
    }

    private function id(string $organizationId,string $code):string
    {
        return $organizationId==='default'
            ? $code
            : substr(hash('sha256',$organizationId.':policy:'.$code),0,32);
    }
}
