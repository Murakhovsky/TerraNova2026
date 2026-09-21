<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Kernel\Agent\Model\AgentRunStatus;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentStatus', template: 'components/experience/cos_agent_status.html.twig')]
final class CosAgentStatus
{
    public AgentRunStatus $status;
}
