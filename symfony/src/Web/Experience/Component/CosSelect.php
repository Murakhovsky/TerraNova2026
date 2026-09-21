<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosSelect',
    template: 'components/experience/cos_select.html.twig',
)]
final class CosSelect
{
    public string $name = '';
    public string $label = '';
    /** @var array<string,string> */
    public array $options = [];
    public ?string $selected = null;
    public bool $disabled = false;
}
