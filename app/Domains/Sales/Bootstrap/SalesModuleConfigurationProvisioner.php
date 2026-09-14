<?php
declare(strict_types=1);

namespace Domains\Sales\Bootstrap;

use Kernel\Configuration\Service\ConfigurationProvisioner;
use Kernel\Module\Contract\ModuleConfigurationProvisionerInterface;

final readonly class SalesModuleConfigurationProvisioner implements ModuleConfigurationProvisionerInterface
{
    public function __construct(private ConfigurationProvisioner $configuration)
    {
    }

    public function provision(string $organizationId, string $actorId): array
    {
        return $this->configuration->provisionDomain($organizationId, 'sales', $actorId);
    }
}
