<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\AgentActionView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentAction', template: 'components/experience/cos_agent_action.html.twig')]
final class CosAgentAction
{
    public AgentActionView $action;
}
