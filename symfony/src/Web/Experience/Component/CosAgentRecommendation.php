<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\AgentRecommendation;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentRecommendation', template: 'components/experience/cos_agent_recommendation.html.twig')]
final class CosAgentRecommendation
{
    public AgentRecommendation $recommendation;
}
