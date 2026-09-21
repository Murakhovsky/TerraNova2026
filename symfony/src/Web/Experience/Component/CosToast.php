<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosToast',
    template: 'components/experience/cos_toast.html.twig',
)]
final class CosToast
{
    public string $id = 'cos-toast';
    public string $title = '';
    public string $message = '';
    public string $tone = 'info';
    public int $duration = 5000;
    public bool $dismissible = true;

    public function cssClass(): string
    {
        $tone = in_array($this->tone, ['positive', 'warning', 'danger', 'info'], true)
            ? $this->tone
            : 'info';

        return 'cos-toast cos-toast--' . $tone;
    }

    public function liveRole(): string
    {
        return in_array($this->tone, ['warning', 'danger'], true) ? 'alert' : 'status';
    }
}
