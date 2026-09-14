<?php
declare(strict_types=1);

namespace Domains\Property\Bootstrap;

use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;

final readonly class PropertyModuleConfigurationProvisioner implements ModuleConfigurationProvisionerInterface
{
    public function __construct(private ConfigurationProvisioner $configuration)
    {
    }

    public function provision(string $organizationId, string $actorId): array
    {
        return $this->configuration->provisionDomain($organizationId, 'property', $actorId);
    }
}
