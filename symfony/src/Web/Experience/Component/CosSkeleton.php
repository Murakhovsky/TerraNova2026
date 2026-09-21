<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosSkeleton',
    template: 'components/experience/cos_skeleton.html.twig',
)]
final class CosSkeleton
{
    public string $shape = 'line';

    public function cssClass(): string
    {
        return match ($this->shape) {
            'circle' => 'cos-skeleton cos-skeleton--circle',
            'block' => 'cos-skeleton cos-skeleton--block',
            default => 'cos-skeleton',
        };
    }
}
