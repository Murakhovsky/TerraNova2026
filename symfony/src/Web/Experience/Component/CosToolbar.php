<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosToolbar', template: 'components/experience/cos_toolbar.html.twig')]
final class CosToolbar
{
    public string $label = 'Toolbar';
    public string $density = 'default';

    public function cssClass(): string
    {
        $density = $this->density === 'compact' ? 'compact' : 'default';

        return 'cos-toolbar cos-toolbar--' . $density;
    }
}
