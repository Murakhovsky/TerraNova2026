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
    public string $id = 'cos-tooltip';
    public string $label = 'Info';
    public string $text = '';
    public string $placement = 'top';

    public function cssClass(): string
    {
        $placement = in_array($this->placement, ['top', 'end', 'bottom', 'start'], true)
            ? $this->placement
            : 'top';

        return 'cos-tooltip-host cos-tooltip-host--' . $placement;
    }
}
