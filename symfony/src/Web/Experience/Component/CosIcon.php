<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosIcon',
    template: 'components/experience/cos_icon.html.twig',
)]
final class CosIcon
{
    public string $name = '';
    public string $size = '1em';
    public ?string $label = null;
}
