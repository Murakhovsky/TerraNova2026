<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosStickyActions',
    template: 'components/experience/cos_sticky_actions.html.twig',
)]
final class CosStickyActions
{
    public string $label = 'Form actions';
    public string $state = 'normal';

    public function cssClass(): string
    {
        $state = in_array($this->state, ['normal', 'disabled', 'loading'], true)
            ? $this->state
            : 'normal';

        return 'cos-sticky-actions cos-sticky-actions--' . $state;
    }
}
