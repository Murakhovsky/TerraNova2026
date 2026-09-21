<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'CosBulkActionBar', template: 'components/experience/cos_bulk_action_bar.html.twig')]
final class CosBulkActionBar
{
    public int $count = 0;
    public string $label = 'selected';
}
