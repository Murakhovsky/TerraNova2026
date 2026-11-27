<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosProgress',
    template: 'components/experience/cos_progress.html.twig',
)]
final class CosProgress
{
    public int $value = 0;
    public int $max = 100;
    public string $label = 'Progress';

    public function clampedValue(): int
    {
        return max(0, min(max(0, $this->max), $this->value));
    }

    public function percent(): int
    {
        if ($this->max <= 0) {
            return 0;
        }

        return max(0, min(100, (int) round(($this->value / $this->max) * 100)));
    }
}
