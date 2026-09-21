<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosCheckbox',
    template: 'components/experience/cos_checkbox.html.twig',
)]
final class CosCheckbox
{
    public string $name = '';
    public string $label = '';
    public bool $checked = false;
    public bool $disabled = false;
}
