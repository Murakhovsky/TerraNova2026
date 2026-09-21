<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Contract;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;

interface ActionProviderInterface extends WebExtensionProviderInterface
{
    /** @return list<UIAction> */
    public function actions(WebExtensionContext $context, ?EntityRef $entity = null): array;
}
