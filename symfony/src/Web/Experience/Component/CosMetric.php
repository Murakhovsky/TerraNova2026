<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosMetric',
    template: 'components/experience/cos_metric.html.twig',
)]
final class CosMetric
{
    public string $label = '';
    public string $value = '';
    public ?string $hint = null;
}
