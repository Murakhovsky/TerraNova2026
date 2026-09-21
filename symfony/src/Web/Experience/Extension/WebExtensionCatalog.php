<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Async\AsyncOperationWebProvider;
use App\Web\Experience\Extension\Model\WebExtensionContext;

final readonly class WebExtensionCatalog
{
    public function __construct(
        private WebExtensionProviderRegistry $registry,
        private AsyncOperationWebProvider $asyncOperations,
    ) {
    }

    public function forContext(WebExtensionContext $context): WebExtensionContextCatalog
    {
        return new WebExtensionContextCatalog(
            $context,
            $this->registry->forContext($context),
            $this->asyncOperations,
        );
    }
}
