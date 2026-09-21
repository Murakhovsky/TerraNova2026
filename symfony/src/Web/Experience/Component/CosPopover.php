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
    public string $title = '';
    public string $copy = '';
}
