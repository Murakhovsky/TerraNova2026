<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosAvatar',
    template: 'components/experience/cos_avatar.html.twig',
)]
final class CosAvatar
{
    public string $initials = '';
    public string $name = '';
    public string $size = 'md';

    public function cssClass(): string
    {
        return match ($this->size) {
            'sm' => 'cos-avatar cos-avatar--sm',
            'lg' => 'cos-avatar cos-avatar--lg',
            default => 'cos-avatar',
        };
    }
}
