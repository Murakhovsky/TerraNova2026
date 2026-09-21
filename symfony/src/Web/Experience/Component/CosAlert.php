<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosAlert',
    template: 'components/experience/cos_alert.html.twig',
)]
final class CosAlert
{
    public string $title = '';
    public string $copy = '';
    public string $tone = 'info';

    public function cssClass(): string
    {
        $tone = in_array($this->tone, ['positive', 'warning', 'danger', 'info'], true)
            ? $this->tone
            : 'info';

        return 'cos-alert cos-alert--' . $tone;
    }

    public function icon(): string
    {
        return match ($this->tone) {
            'positive' => 'activity',
            'warning', 'danger' => 'notification',
            default => 'notification',
        };
    }
}
