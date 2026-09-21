<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosPopover',
    template: 'components/experience/cos_popover.html.twig',
)]
final class CosPopover
{
    public string $id = 'cos-popover';
    public string $triggerLabel = 'Details';
    public string $title = '';
    public string $copy = '';
    public string $placement = 'bottom';

    public function cssClass(): string
    {
        $placement = in_array($this->placement, ['top', 'end', 'bottom', 'start'], true)
            ? $this->placement
            : 'bottom';

        return 'cos-popover-host cos-popover-host--' . $placement;
    }
}
