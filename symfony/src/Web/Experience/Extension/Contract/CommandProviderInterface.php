<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Shell\ShellCommandItem;

interface CommandProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<ShellCommandItem> */
    public function commands(WebExtensionContext $context): array;
}
