<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final class ModuleExtensionRegistry
{
    public const API_ROUTES = 'api.routes';
    public const TENANT_CONFIGURATION = 'tenant.configuration';
    public const EVENT_CONSUMERS = 'event.consumers';

    /** @var array<string, list<ModuleExtensionContribution>> */
    private array $extensions = [];

    public function __construct(ModuleCatalog $catalog)
    {
        foreach ($catalog->definitions() as $definition) {
            $moduleId = $definition->manifest->id;
            $contributions = $definition->contributions;

            foreach ($contributions->apiRouteContributorServices as $serviceId) {
                $this->register($moduleId, self::API_ROUTES, $serviceId);
            }
            foreach ($contributions->configurationProvisionerServices as $serviceId) {
                $this->register($moduleId, self::TENANT_CONFIGURATION, $serviceId);
            }
            foreach ($contributions->extensionServices as $extensionPoint => $serviceIds) {
                foreach ($serviceIds as $serviceId) {
                    $this->register($moduleId, $extensionPoint, $serviceId);
                }
            }
        }
    }

    /** @return list<ModuleExtensionContribution> */
    public function for(string $extensionPoint): array
    {
        self::assertExtensionPoint($extensionPoint);
        return $this->extensions[$extensionPoint] ?? [];
    }

    /** @return list<string> */
    public function extensionPoints(): array
    {
        $points = array_keys($this->extensions);
        sort($points, SORT_STRING);
        return $points;
    }

    /** @return array<string, list<ModuleExtensionContribution>> */
    public function all(): array
    {
        $result = $this->extensions;
        ksort($result, SORT_STRING);
        return $result;
    }

    private function register(string $moduleId, string $extensionPoint, string $serviceId): void
    {
        self::assertExtensionPoint($extensionPoint);

        foreach ($this->extensions[$extensionPoint] ?? [] as $existing) {
            if ($existing->moduleId === $moduleId && $existing->serviceId === $serviceId) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate extension contribution %s:%s for module %s.',
                    $extensionPoint,
                    $serviceId,
                    $moduleId,
                ));
            }
        }

        $this->extensions[$extensionPoint][] = new ModuleExtensionContribution(
            $moduleId,
            $extensionPoint,
            $serviceId,
        );
    }

    private static function assertExtensionPoint(string $extensionPoint): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $extensionPoint)) {
            throw new InvalidArgumentException(sprintf('Invalid module extension point: %s.', $extensionPoint));
        }
    }
}
