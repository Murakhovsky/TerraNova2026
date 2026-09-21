<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\AI\StructuredAgentResult;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosAgentResult', template: 'components/experience/cos_agent_result.html.twig')]
final class CosAgentResult
{
    public StructuredAgentResult $result;
}
