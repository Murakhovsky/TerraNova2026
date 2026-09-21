<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosSwitch',
    template: 'components/experience/cos_switch.html.twig',
)]
final class CosSwitch
{
    public string $name = '';
    public string $label = '';
    public bool $checked = false;
    public bool $disabled = false;
}
