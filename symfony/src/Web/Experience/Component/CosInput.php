<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosInput',
    template: 'components/experience/cos_input.html.twig',
)]
final class CosInput
{
    public string $name = '';
    public string $label = '';
    public string $type = 'text';
    public string $value = '';
    public ?string $placeholder = null;
    public bool $disabled = false;
    public bool $required = false;
}
