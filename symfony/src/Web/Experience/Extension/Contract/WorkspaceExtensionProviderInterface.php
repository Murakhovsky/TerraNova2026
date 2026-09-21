<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceExtension;

interface WorkspaceExtensionProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<WorkspaceExtension> */
    public function extensions(WebExtensionContext $context, string $workspaceId): array;
}
