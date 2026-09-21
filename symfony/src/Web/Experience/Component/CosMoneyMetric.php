<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosMoneyMetric', template: 'components/experience/cos_money_metric.html.twig')]
final class CosMoneyMetric
{
    public string $label = '';
    public string $value = '';
    public ?string $delta = null;
    public ?string $target = null;
    public ?int $progress = null;
}
