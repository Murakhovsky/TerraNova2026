<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosStage', template: 'components/experience/cos_stage.html.twig')]
final class CosStage
{
    public string $label = '';
    public string $state = 'upcoming';

    public function cssClass(): string
    {
        $state = in_array($this->state, ['complete', 'current', 'upcoming', 'blocked'], true)
            ? $this->state
            : 'upcoming';

        return 'cos-stage cos-stage--' . $state;
    }
}
