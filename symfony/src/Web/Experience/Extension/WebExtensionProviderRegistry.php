<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Extension\Contract\WebExtensionProviderInterface;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use InvalidArgumentException;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\ModuleExtensionRegistry;

final class WebExtensionProviderRegistry
{
    /** @var array<string, WebExtensionProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<WebExtensionProviderInterface> $providers
     */
    public function __construct(
        private readonly ModuleExtensionRegistry $extensions,
        private readonly ActiveModuleResolver $modules,
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            if (!$provider instanceof WebExtensionProviderInterface) {
                throw new InvalidArgumentException('Web extension providers must implement WebExtensionProviderInterface.');
            }

            $serviceId = trim($provider->serviceId());
            if ($serviceId === '' || !preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $serviceId)) {
                throw new InvalidArgumentException(sprintf('Invalid Web extension provider service id: %s.', $serviceId));
            }

            if (isset($this->providers[$serviceId])) {
                throw new InvalidArgumentException(sprintf('Duplicate Web extension provider service id: %s.', $serviceId));
            }

            $this->providers[$serviceId] = $provider;
        }
    }

    public function forContext(WebExtensionContext $context): WebExtensionProviderSet
    {
        return new WebExtensionProviderSet(
            $context,
            $this->extensions,
            $this->modules->snapshot($context->organizationId),
            $this->providers,
        );
    }
}
