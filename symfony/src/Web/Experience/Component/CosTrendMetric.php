<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosTrendMetric', template: 'components/experience/cos_trend_metric.html.twig')]
final class CosTrendMetric
{
    public string $label = '';
    public string $value = '';
    public ?string $delta = null;
    public string $direction = 'neutral';
    public ?string $period = null;

    public function directionClass(): string
    {
        $direction = in_array($this->direction, ['up', 'down', 'neutral'], true)
            ? $this->direction
            : 'neutral';

        return 'cos-trend-metric cos-trend-metric--' . $direction;
    }
}
