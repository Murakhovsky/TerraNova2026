<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosModal',
    template: 'components/experience/cos_modal.html.twig',
)]
final class CosModal
{
    public string $id = 'cos-modal';
    public string $title = '';
    public string $triggerLabel = '';
    public string $size = 'md';
    public string $closeLabel = 'Close';
    public bool $dismissible = true;

    public function cssClass(): string
    {
        $size = in_array($this->size, ['sm', 'md', 'lg', 'xl'], true) ? $this->size : 'md';

        return 'cos-dialog cos-dialog--modal cos-dialog--' . $size;
    }
}
