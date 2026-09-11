<?php
declare(strict_types=1);

namespace Kernel\Module;

use InvalidArgumentException;

final readonly class ModuleContributions
{
    /**
     * @param list<string> $jobHandlerServices
     * @param list<string> $apiRouteContributorServices
     * @param list<string> $capabilities
     * @param list<string> $migrationFiles
     * @param list<string> $configurationProvisionerServices
     * @param array<string, list<string>> $extensionServices
     */
    public function __construct(
        public ?string $runtimeModuleService = null,
        public array $jobHandlerServices = [],
        public array $apiRouteContributorServices = [],
        public array $capabilities = [],
        public array $migrationFiles = [],
        public array $configurationProvisionerServices = [],
        public array $extensionServices = [],
    ) {
        if ($this->runtimeModuleService !== null) {
            self::assertServiceId($this->runtimeModuleService);
        }

        foreach ([
            'job handler' => $this->jobHandlerServices,
            'API route contributor' => $this->apiRouteContributorServices,
            'configuration provisioner' => $this->configurationProvisionerServices,
        ] as $kind => $serviceIds) {
            self::assertUniqueStrings($serviceIds, $kind);
            foreach ($serviceIds as $serviceId) {
                self::assertServiceId($serviceId);
            }
        }

        foreach ($this->extensionServices as $extensionPoint => $serviceIds) {
            if (!is_string($extensionPoint) || !preg_match('/^[a-z][a-z0-9_.:-]*$/', $extensionPoint)) {
                throw new InvalidArgumentException(sprintf('Invalid module extension point: %s.', (string) $extensionPoint));
            }
            self::assertUniqueStrings($serviceIds, sprintf('extension service for %s', $extensionPoint));
            foreach ($serviceIds as $serviceId) {
                self::assertServiceId($serviceId);
            }
        }

        self::assertUniqueStrings($this->capabilities, 'capability');
        foreach ($this->capabilities as $capability) {
            if (!preg_match('/^[a-z][a-z0-9_.:-]*$/', $capability)) {
                throw new InvalidArgumentException(sprintf('Invalid module capability: %s.', $capability));
            }
        }

        self::assertUniqueStrings($this->migrationFiles, 'migration file');
        foreach ($this->migrationFiles as $migrationFile) {
            if (
                $migrationFile === ''
                || str_starts_with($migrationFile, '/')
                || str_contains($migrationFile, '..')
                || !str_ends_with($migrationFile, '.sql')
            ) {
                throw new InvalidArgumentException(sprintf('Invalid module migration path: %s.', $migrationFile));
            }
        }
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $runtime = $definition['runtime_module_service'] ?? null;

        return new self(
            is_string($runtime) && $runtime !== '' ? $runtime : null,
            self::stringList($definition['job_handler_services'] ?? []),
            self::stringList($definition['api_route_contributor_services'] ?? []),
            self::stringList($definition['capabilities'] ?? []),
            self::stringList($definition['migration_files'] ?? []),
            self::stringList($definition['configuration_provisioner_services'] ?? []),
            self::extensionMap($definition['extension_services'] ?? []),
        );
    }

    /** @return list<string> */
    public function allServiceIds(): array
    {
        $extensionServiceIds = [];
        foreach ($this->extensionServices as $serviceIds) {
            array_push($extensionServiceIds, ...$serviceIds);
        }

        return array_values(array_unique(array_filter([
            $this->runtimeModuleService,
            ...$this->jobHandlerServices,
            ...$this->apiRouteContributorServices,
            ...$this->configurationProvisionerServices,
            ...$extensionServiceIds,
        ], static fn (?string $value): bool => $value !== null)));
    }

    /** @param mixed $value @return list<string> */
    private static function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /** @param mixed $value @return array<string, list<string>> */
    private static function extensionMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $extensions = [];
        foreach ($value as $extensionPoint => $serviceIds) {
            if (!is_string($extensionPoint)) {
                throw new InvalidArgumentException('Module extension point names must be strings.');
            }
            $extensions[$extensionPoint] = self::stringList($serviceIds);
        }

        return $extensions;
    }

    /** @param list<string> $values */
    private static function assertUniqueStrings(array $values, string $kind): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException(sprintf('Module contains duplicate %s contributions.', $kind));
        }
    }

    private static function assertServiceId(string $serviceId): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9._-]*$/', $serviceId)) {
            throw new InvalidArgumentException(sprintf('Invalid DI service id in module contribution: %s.', $serviceId));
        }
    }
}
