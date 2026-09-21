<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosSpinner',
    template: 'components/experience/cos_spinner.html.twig',
)]
final class CosSpinner
{
    public string $label = 'Loading';
}
