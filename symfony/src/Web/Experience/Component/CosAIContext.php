<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosAIContext',
    template: 'components/experience/cos_ai_context.html.twig',
)]
final class CosAIContext
{
    public string $id = 'cos-ai-context';
    public string $title = 'AI context';
}
