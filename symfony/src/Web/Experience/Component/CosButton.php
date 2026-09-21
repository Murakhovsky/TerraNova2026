<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosButton',
    template: 'components/experience/cos_button.html.twig',
)]
final class CosButton
{
    public string $label = '';
    public string $variant = 'secondary';
    public string $size = 'md';
    public ?string $href = null;
    public string $type = 'button';
    public bool $disabled = false;

    public function cssClass(): string
    {
        $variant = in_array($this->variant, ['primary', 'secondary', 'ghost', 'danger'], true)
            ? $this->variant
            : 'secondary';

        $size = in_array($this->size, ['sm', 'md', 'lg'], true)
            ? $this->size
            : 'md';

        $classes = ['cos-button', 'cos-button--' . $variant];
        if ($size !== 'md') {
            $classes[] = 'cos-button--' . $size;
        }

        return implode(' ', $classes);
    }
}
