<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosPageHeader', template: 'components/experience/cos_page_header.html.twig')]
final class CosPageHeader
{
    public string $eyebrow = '';
    public string $title = '';
    public ?string $subtitle = null;
    public ?string $metaLabel = null;
    public ?string $metaValue = null;
}
