<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Extension\Model\DashboardWidget;
use App\Web\Experience\Extension\Model\WebExtensionContext;

interface DashboardWidgetProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<DashboardWidget> */
    public function widgets(WebExtensionContext $context, string $dashboardId): array;
}
