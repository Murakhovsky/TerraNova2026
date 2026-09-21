<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\Workspace\WorkspaceViewModel;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosWorkspaceHeader',
    template: 'components/experience/cos_workspace_header.html.twig',
)]
final class CosWorkspaceHeader
{
    public WorkspaceViewModel $workspace;
}
