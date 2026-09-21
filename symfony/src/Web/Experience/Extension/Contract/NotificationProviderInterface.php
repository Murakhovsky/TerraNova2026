<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\NotificationItem;
use App\Web\Experience\Extension\Model\WebExtensionContext;

interface NotificationProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<NotificationItem> */
    public function notifications(WebExtensionContext $context, int $limit = 20): array;
}
