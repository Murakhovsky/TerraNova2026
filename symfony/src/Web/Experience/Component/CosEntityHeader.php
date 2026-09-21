<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosEntityHeader', template: 'components/experience/cos_entity_header.html.twig')]
final class CosEntityHeader
{
    public string $eyebrow = '';
    public string $identity = '';
    public string $title = '';
    public ?string $subtitle = null;
    public ?string $statusLabel = null;
    public string $statusTone = 'neutral';

    /** @var list<array{label:string,value:string}> */
    public array $meta = [];
}
