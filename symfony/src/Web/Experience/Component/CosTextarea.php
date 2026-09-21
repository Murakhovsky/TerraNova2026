<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosTextarea',
    template: 'components/experience/cos_textarea.html.twig',
)]
final class CosTextarea
{
    public string $name = '';
    public string $label = '';
    public string $value = '';
    public ?string $placeholder = null;
    public bool $disabled = false;
    public bool $required = false;
}
