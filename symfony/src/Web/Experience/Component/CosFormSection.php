<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosFormSection',
    template: 'components/experience/cos_form_section.html.twig',
)]
final class CosFormSection
{
    public string $title = '';
    public ?string $description = null;
    public bool $disabled = false;
    public ?string $error = null;

    public function cssClass(): string
    {
        $classes = ['cos-form-section'];
        if ($this->error !== null && trim($this->error) !== '') {
            $classes[] = 'cos-form-section--error';
        }
        if ($this->disabled) {
            $classes[] = 'cos-form-section--disabled';
        }

        return implode(' ', $classes);
    }
}
