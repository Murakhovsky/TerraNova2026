<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosActivityFeed', template: 'components/experience/cos_activity_feed.html.twig')]
final class CosActivityFeed
{
    public string $label = 'Activity';

    /** @var list<array{actor:string,action:string,time:string,detail?:string,source?:string}> */
    public array $items = [];
}
