<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosFilterBar', template: 'components/experience/cos_filter_bar.html.twig')]
final class CosFilterBar
{
    public string $label = 'Filters';
    public string $action = '';
    public string $method = 'get';

    public function formMethod(): string
    {
        return strtolower($this->method) === 'post' ? 'post' : 'get';
    }
}
