<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\Workspace\WorkspaceViewModel;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosWorkspace',
    template: 'components/experience/cos_workspace.html.twig',
)]
final class CosWorkspace
{
    public WorkspaceViewModel $workspace;
}
