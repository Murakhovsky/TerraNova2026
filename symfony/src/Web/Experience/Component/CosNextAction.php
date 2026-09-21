<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosNextAction', template: 'components/experience/cos_next_action.html.twig')]
final class CosNextAction
{
    public string $title = '';
    public ?string $copy = null;
    public ?string $due = null;
    public string $tone = 'neutral';
    public ?string $href = null;
}
