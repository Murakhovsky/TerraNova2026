<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\AgentWarning;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentWarning', template: 'components/experience/cos_agent_warning.html.twig')]
final class CosAgentWarning
{
    public AgentWarning $warning;
}
