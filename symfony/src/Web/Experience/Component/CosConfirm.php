<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosConfirm',
    template: 'components/experience/cos_confirm.html.twig',
)]
final class CosConfirm
{
    public string $id = 'cos-confirm';
    public string $title = 'Confirm action';
    public string $message = '';
    public string $triggerLabel = '';
    public string $confirmLabel = 'Confirm';
    public string $cancelLabel = 'Cancel';
    public string $tone = 'danger';
    public bool $stepUp = false;

    public function toneClass(): string
    {
        $tone = in_array($this->tone, ['primary', 'warning', 'danger'], true) ? $this->tone : 'danger';

        return 'cos-confirm cos-confirm--' . $tone;
    }
}
