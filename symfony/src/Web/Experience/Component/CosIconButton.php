<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosIconButton',
    template: 'components/experience/cos_icon_button.html.twig',
)]
final class CosIconButton
{
    public string $icon = '';
    public string $label = '';
    public string $variant = 'secondary';
    public string $type = 'button';
    public bool $disabled = false;

    public function cssClass(): string
    {
        $variant = in_array($this->variant, ['secondary', 'primary', 'danger'], true)
            ? $this->variant
            : 'secondary';

        return $variant === 'secondary'
            ? 'cos-icon-button'
            : 'cos-icon-button cos-icon-button--' . $variant;
    }
}
