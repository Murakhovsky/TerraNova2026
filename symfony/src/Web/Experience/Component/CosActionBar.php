<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosActionBar', template: 'components/experience/cos_action_bar.html.twig')]
final class CosActionBar
{
    public string $label = 'Actions';
    public string $align = 'end';
    public bool $sticky = false;

    public function cssClass(): string
    {
        $align = in_array($this->align, ['start', 'between', 'end'], true) ? $this->align : 'end';

        return 'cos-action-bar cos-action-bar--' . $align . ($this->sticky ? ' cos-action-bar--sticky' : '');
    }
}
