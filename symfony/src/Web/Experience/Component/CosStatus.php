<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosStatus',
    template: 'components/experience/cos_status.html.twig',
)]
final class CosStatus
{
    public string $label = '';
    public string $tone = 'neutral';

    public function cssClass(): string
    {
        $tone = in_array($this->tone, ['neutral', 'positive', 'warning', 'danger', 'info'], true)
            ? $this->tone
            : 'neutral';

        return $tone === 'neutral'
            ? 'cos-status'
            : 'cos-status cos-status--' . $tone;
    }
}
