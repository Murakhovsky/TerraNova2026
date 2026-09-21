<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosRelations', template: 'components/experience/cos_relations.html.twig')]
final class CosRelations
{
    public string $title = 'Relations';

    /** @var list<array{type:string,label:string,meta?:string,href?:string}> */
    public array $items = [];
}
