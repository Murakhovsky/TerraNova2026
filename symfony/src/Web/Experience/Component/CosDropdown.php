<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosDropdown',
    template: 'components/experience/cos_dropdown.html.twig',
)]
final class CosDropdown
{
    public string $id = 'cos-dropdown';
    public string $label = 'Menu';
    public string $align = 'start';

    public function cssClass(): string
    {
        $align = in_array($this->align, ['start', 'end'], true) ? $this->align : 'start';

        return 'cos-dropdown cos-dropdown--' . $align;
    }
}
