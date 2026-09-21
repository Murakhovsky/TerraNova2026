<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosBadge',
    template: 'components/experience/cos_badge.html.twig',
)]
final class CosBadge
{
    public string $label = '';
    public string $tone = 'neutral';

    public function cssClass(): string
    {
        $tone = in_array($this->tone, ['neutral', 'positive', 'warning', 'danger', 'info'], true)
            ? $this->tone
            : 'neutral';

        return $tone === 'neutral'
            ? 'cos-badge'
            : 'cos-badge cos-badge--' . $tone;
    }
}
