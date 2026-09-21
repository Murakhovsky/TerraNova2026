<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;

interface WorkspaceProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<WorkspaceDefinition> */
    public function workspaces(WebExtensionContext $context): array;
}
