<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\AgentRunViewModel;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentRun', template: 'components/experience/cos_agent_run.html.twig')]
final class CosAgentRun
{
    public AgentRunViewModel $run;
}
