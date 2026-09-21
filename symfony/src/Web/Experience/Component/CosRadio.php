<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosRadio',
    template: 'components/experience/cos_radio.html.twig',
)]
final class CosRadio
{
    public string $name = '';
    public string $label = '';
    public string $value = '';
    public bool $checked = false;
    public bool $disabled = false;
}
