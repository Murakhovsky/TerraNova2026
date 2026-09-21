<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;

interface EntityLinkProviderInterface extends WebExtensionProviderInterface
{
    public function link(WebExtensionContext $context, EntityRef $entity): ?EntityLink;
}
