<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\AgentMetric;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentMetric', template: 'components/experience/cos_agent_metric.html.twig')]
final class CosAgentMetric
{
    public AgentMetric $metric;
}
