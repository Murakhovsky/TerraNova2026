<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosStickyActions', template: 'components/experience/cos_sticky_actions.html.twig')]
final class CosStickyActions
{
    public string $label = 'Form actions';
}
