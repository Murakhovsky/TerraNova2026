<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\ActivityItem;
use App\Web\Experience\Extension\Model\WebExtensionContext;

interface ActivityProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<ActivityItem> */
    public function activities(WebExtensionContext $context, int $limit = 20): array;
}
