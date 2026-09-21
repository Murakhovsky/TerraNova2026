<?php
declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'ExperienceRuntimeProbe',
    template: 'components/experience/runtime_probe.html.twig',
)]
final class ExperienceRuntimeProbe
{
    public string $runtime = 'twig-component';
}
