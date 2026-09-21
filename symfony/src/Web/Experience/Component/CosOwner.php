<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosOwner', template: 'components/experience/cos_owner.html.twig')]
final class CosOwner
{
    public string $name = '';
    public ?string $role = null;
    public string $initials = '';
}
