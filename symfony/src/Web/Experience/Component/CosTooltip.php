<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosTooltip',
    template: 'components/experience/cos_tooltip.html.twig',
)]
final class CosTooltip
{
    public string $text = '';
}
