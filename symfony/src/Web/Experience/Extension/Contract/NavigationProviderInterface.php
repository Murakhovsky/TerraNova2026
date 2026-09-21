<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;

interface NavigationProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<NavigationContribution> */
    public function navigation(WebExtensionContext $context): array;
}
