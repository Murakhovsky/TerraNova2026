<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosEntityCard', template: 'components/experience/cos_entity_card.html.twig')]
final class CosEntityCard
{
    public string $eyebrow = '';
    public string $title = '';
    public ?string $subtitle = null;
    public ?string $statusLabel = null;
    public string $statusTone = 'neutral';
    public ?string $href = null;

    /** @var list<array{label:string,value:string}> */
    public array $meta = [];
}
