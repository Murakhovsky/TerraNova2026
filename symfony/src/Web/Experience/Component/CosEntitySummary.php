<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosEntitySummary', template: 'components/experience/cos_entity_summary.html.twig')]
final class CosEntitySummary
{
    public string $label = 'Entity summary';

    /** @var list<array{label:string,value:string,hint?:string}> */
    public array $items = [];
}
