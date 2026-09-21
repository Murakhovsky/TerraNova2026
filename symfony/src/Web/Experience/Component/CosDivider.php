<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosDivider',
    template: 'components/experience/cos_divider.html.twig',
)]
final class CosDivider
{
}
