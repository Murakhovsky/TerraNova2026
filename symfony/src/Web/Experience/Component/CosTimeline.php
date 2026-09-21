<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosTimeline', template: 'components/experience/cos_timeline.html.twig')]
final class CosTimeline
{
    public string $label = 'Timeline';

    /** @var list<array{time:string,title:string,copy?:string,tone?:string}> */
    public array $items = [];
}
