<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosEntityListItem', template: 'components/experience/cos_entity_list_item.html.twig')]
final class CosEntityListItem
{
    public string $title = '';
    public ?string $subtitle = null;
    public ?string $statusLabel = null;
    public string $statusTone = 'neutral';
    public ?string $href = null;
    public ?string $meta = null;
}
