<?php
declare(strict_types=1);

namespace App\Web\Experience\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent(
    name: 'ExperienceRuntimeStatus',
    template: 'components/experience/runtime_status.html.twig',
)]
final class ExperienceRuntimeStatus
{
    use DefaultActionTrait;

    public string $status = 'live-component-ready';
}
