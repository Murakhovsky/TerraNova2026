<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosActivityPanel',
    template: 'components/experience/cos_activity_panel.html.twig',
)]
final class CosActivityPanel
{
    public string $id = 'cos-activity-panel';
    public string $title = 'Activity';
}
