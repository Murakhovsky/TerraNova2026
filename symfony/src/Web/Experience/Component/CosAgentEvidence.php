<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\AgentEvidence;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentEvidence', template: 'components/experience/cos_agent_evidence.html.twig')]
final class CosAgentEvidence
{
    public AgentEvidence $evidence;
}
