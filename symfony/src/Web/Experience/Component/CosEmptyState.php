<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosEmptyState',
    template: 'components/experience/cos_empty_state.html.twig',
)]
final class CosEmptyState
{
    public string $title = '';
    public string $copy = '';
}
