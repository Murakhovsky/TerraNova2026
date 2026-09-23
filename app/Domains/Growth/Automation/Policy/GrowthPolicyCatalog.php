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
            new ActionPolicy(
                $this->id($organizationId,'growth-send-message-approval-v1'),
                $organizationId,
                'growth.send_message',
                [],
                PolicyDecision::ApprovalRequired,
                10,
                'Growth outbound message approval',
                'Pre-handoff outbound messaging requires explicit approval.',
            ),
        ];
    }

    private function id(string $organizationId,string $code):string
    {
        return $organizationId==='default'
            ? $code
            : substr(hash('sha256',$organizationId.':policy:'.$code),0,32);
    }
}
