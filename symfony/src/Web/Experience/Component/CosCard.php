<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosCard',
    template: 'components/experience/cos_card.html.twig',
)]
final class CosCard
{
    public string $title = '';
    public ?string $eyebrow = null;
    public ?string $copy = null;
    public bool $raised = false;
}
