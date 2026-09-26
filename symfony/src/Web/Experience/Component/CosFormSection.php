<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosFormSection', template: 'components/experience/cos_form_section.html.twig')]
final class CosFormSection
{
    public string $title = '';
    public ?string $description = null;
}
