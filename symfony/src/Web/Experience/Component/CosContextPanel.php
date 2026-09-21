<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosContextPanel',
    template: 'components/experience/cos_context_panel.html.twig',
)]
final class CosContextPanel
{
    public string $id = 'cos-context-panel';
    public string $title = 'Context';
}
